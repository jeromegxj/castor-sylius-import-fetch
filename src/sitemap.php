<?php

declare(strict_types=1);

namespace SyliusStarter\ImportFetch;

use SyliusStarter\Import\Dto\CollectionExtraction;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

use const SyliusStarter\Import\HTML_MAX_LENGTH;
use const SyliusStarter\Import\IMPORT_LOCALE_PREFERENCE;
use const SyliusStarter\Import\IMPORT_PLATFORM_SHOPIFY_THRESHOLD;

use function SyliusStarter\Import\clean_html_for_ai;
use function SyliusStarter\Import\create_ai_platform;
use function SyliusStarter\Import\deserialize_collection_extraction;
use function SyliusStarter\Import\humanize_slug;
use function SyliusStarter\Import\import_log;
use function SyliusStarter\Import\import_url_locale_prefix_pattern;
use function SyliusStarter\Import\invoke_ai_structured;
use function SyliusStarter\Import\is_category_url;
use function SyliusStarter\Import\is_product_url;
use function SyliusStarter\Import\normalize_label_for_matching;
use function SyliusStarter\Import\product_dedup_key_from_url;
use function SyliusStarter\Import\title_from_url_slug;
use function Castor\context;
use function Castor\fs;
use function Castor\io;
use function Castor\run;

function create_import_context(string $baseUrl, string $host, string $platform, int $platformScore, array $stats = []): array
{
    return [
        'base_url' => $baseUrl,
        'host' => $host,
        'platform' => $platform,
        'platform_score' => $platformScore,
        'locale' => detect_preferred_locale($baseUrl),
        'stats' => $stats,
    ];
}

function detect_preferred_locale(string $baseUrl): string
{
    foreach (IMPORT_LOCALE_PREFERENCE as $locale) {
        if (preg_match('#/' . preg_quote($locale, '#') . '(?:/|$)#', $baseUrl)) {
            return $locale;
        }
    }

    return IMPORT_LOCALE_PREFERENCE[0];
}

/**
 * @param string[] $sitemapUrls
 *
 * @return array{platform: string, score: int}
 */
function detect_import_platform(
    \Symfony\Contracts\HttpClient\HttpClientInterface $client,
    string $baseUrl,
    string $html,
    array $sitemapUrls,
): array {
    $shopifyScore = 0;
    $genericScore = 0;

    try {
        $response = $client->request('GET', $baseUrl . '/collections.json', ['timeout' => 10]);
        $payload = json_decode($response->getContent(), true);

        if (\is_array($payload) && isset($payload['collections']) && \is_array($payload['collections'])) {
            $shopifyScore += 3;
        }
    } catch (\Throwable) {
    }

    foreach ($sitemapUrls as $sitemapUrl) {
        if (preg_match('#sitemap_products#i', $sitemapUrl)) {
            $shopifyScore += 2;
            break;
        }
    }

    if (str_contains($html, 'cdn.shopify.com') || str_contains($html, 'Shopify.')) {
        $shopifyScore += 2;
    }

    foreach ($sitemapUrls as $sitemapUrl) {
        if (preg_match('#sitemap\.products#i', $sitemapUrl)) {
            $genericScore += 2;
            break;
        }
    }

    if (preg_match('#/(?:catalogue|produit)/#', $html)) {
        ++$genericScore;
    }

    $platform = $shopifyScore >= IMPORT_PLATFORM_SHOPIFY_THRESHOLD ? 'shopify' : 'generic';

    return [
        'platform' => $platform,
        'score' => 'shopify' === $platform ? $shopifyScore : $genericScore,
    ];
}

function product_locale_from_url(string $url): ?string
{
    $path = parse_url($url, \PHP_URL_PATH);

    if (!\is_string($path)) {
        return null;
    }

    if (preg_match('#^/(' . import_url_locale_prefix_pattern() . ')(?:/|$)#', $path, $matches)) {
        $locale = $matches[1];

        if (str_contains($locale, '-')) {
            return explode('-', $locale, 2)[0];
        }

        return $locale;
    }

    return null;
}

/**
 * @param array<string, array{url: string, title: string, image: string}> $products
 * @param array<string, mixed>                                           $stats
 *
 * @return array<string, array{url: string, title: string, image: string}>
 */
function deduplicate_products_by_locale(array $products, string $preferredLocale, array &$stats): array
{
    $before = \count($products);
    /** @var array<string, array{url: string, title: string, image: string, locale_rank: int}> $grouped */
    $grouped = [];

    foreach ($products as $product) {
        $dedupKey = product_dedup_key_from_url($product['url']);
        $locale = product_locale_from_url($product['url']);
        $localeRank = null === $locale ? \PHP_INT_MAX : array_search($locale, IMPORT_LOCALE_PREFERENCE, true);

        if (false === $localeRank) {
            $localeRank = \PHP_INT_MAX - 1;
        }

        if ($locale === $preferredLocale) {
            $localeRank = -1;
        }

        if (!isset($grouped[$dedupKey]) || $localeRank < $grouped[$dedupKey]['locale_rank']) {
            $grouped[$dedupKey] = array_merge($product, ['locale_rank' => $localeRank]);
        }
    }

    $deduped = [];

    foreach ($grouped as $product) {
        unset($product['locale_rank']);
        $deduped[$product['url']] = $product;
    }

    $stats['products_after_locale_dedup'] = \count($deduped);
    $stats['products_locale_dedup_removed'] = $before - \count($deduped);

    return $deduped;
}

/**
 * @param string[] $urls
 *
 * @return string[]
 */
function sort_sitemap_urls_by_priority(array $urls, string $platform): array
{
    usort($urls, static fn(string $a, string $b): int => sitemap_url_priority_score($b, $platform) <=> sitemap_url_priority_score($a, $platform));

    return $urls;
}

function sitemap_url_priority_score(string $url, string $platform): int
{
    $score = 0;

    if ('shopify' === $platform && preg_match('#sitemap_products#i', $url)) {
        $score += 10;
    }

    if ('generic' === $platform && preg_match('#sitemap\.products#i', $url)) {
        $score += 10;
    }

    if (preg_match('#product#i', $url)) {
        $score += 5;
    }

    return $score;
}

function is_product_sitemap_url(string $url): bool
{
    return (bool) preg_match('#(?:sitemap[_\.-]?products|products[_\.-]?sitemap|sitemap-products)#i', $url);
}

function is_catalog_sitemap_url(string $url): bool
{
    return (bool) preg_match('#sitemap-catalog#i', $url);
}

function is_auxiliary_sitemap_url(string $url): bool
{
    return is_catalog_sitemap_url($url)
        || (bool) preg_match('#sitemap-(?:marketing|stories|pages)(?:\.|$)#i', $url);
}

/**
 * @return string[]
 */
function default_sitemap_candidate_urls(string $baseUrl): array
{
    $baseUrl = rtrim($baseUrl, '/');

    return [
        $baseUrl . '/sitemap.xml',
        $baseUrl . '/sitemap-index.xml',
        $baseUrl . '/sitemap_index.xml',
        $baseUrl . '/sitemap/sitemap.xml',
    ];
}

/**
 * @return string[]
 */
function extract_sitemap_urls_from_robots(string $robotsContent): array
{
    $urls = [];

    foreach (preg_split('/\r\n|\r|\n/', $robotsContent) as $line) {
        $line = trim($line);

        if ('' === $line || str_starts_with($line, '#')) {
            continue;
        }

        if (preg_match('/^Sitemap:\s*(\S+)/i', $line, $matches)) {
            $url = trim($matches[1]);

            if ('' !== $url) {
                $urls[] = $url;
            }
        }
    }

    return array_values(array_unique($urls));
}

/**
 * @return string[]
 */
function extract_sitemap_urls_from_html(string $html, string $baseUrl): array
{
    $urls = [];

    if (preg_match_all('/<link\b[^>]*rel=["\']sitemap["\'][^>]*href=["\']([^"\']+)["\']/i', $html, $matches)) {
        foreach ($matches[1] as $href) {
            $urls[] = normalize_import_image_url($href, $baseUrl);
        }
    }

    return array_values(array_unique($urls));
}

/**
 * @return string[]
 */
function discover_sitemap_candidate_urls(
    \Symfony\Contracts\HttpClient\HttpClientInterface $client,
    string $baseUrl,
    string $html,
): array {
    $candidates = [];

    try {
        $robotsContent = $client->request('GET', rtrim($baseUrl, '/') . '/robots.txt', ['timeout' => 15])->getContent();
        $candidates = array_merge($candidates, extract_sitemap_urls_from_robots($robotsContent));
    } catch (\Throwable) {
    }

    $candidates = array_merge($candidates, extract_sitemap_urls_from_html($html, $baseUrl));
    $candidates = array_merge($candidates, default_sitemap_candidate_urls($baseUrl));

    return array_values(array_unique($candidates));
}

/**
 * @param string[] $candidateUrls
 *
 * @return array{url: ?string, xml: ?\SimpleXMLElement, failures: array<string, string>}
 */
function resolve_main_sitemap(
    \Symfony\Contracts\HttpClient\HttpClientInterface $client,
    array $candidateUrls,
): array {
    $failures = [];

    foreach ($candidateUrls as $candidateUrl) {
        import_log(\sprintf('Trying sitemap: %s', $candidateUrl));

        try {
            $xml = fetch_sitemap_xml($client, $candidateUrl);

            return [
                'url' => $candidateUrl,
                'xml' => $xml,
                'failures' => $failures,
            ];
        } catch (\Throwable $exception) {
            $failures[$candidateUrl] = $exception->getMessage();
        }
    }

    return [
        'url' => null,
        'xml' => null,
        'failures' => $failures,
    ];
}

function decode_sitemap_content(string $content, string $url): string
{
    if ('' !== $content && "\x1f\x8b" === substr($content, 0, 2)) {
        $decoded = @gzdecode($content);

        if (false !== $decoded && '' !== $decoded) {
            return $decoded;
        }
    }

    $path = parse_url($url, \PHP_URL_PATH);

    if (\is_string($path) && str_ends_with(strtolower($path), '.gz')) {
        $decoded = @gzdecode($content);

        if (false !== $decoded && '' !== $decoded) {
            return $decoded;
        }
    }

    return $content;
}

function fetch_sitemap_xml(\Symfony\Contracts\HttpClient\HttpClientInterface $client, string $url): \SimpleXMLElement
{
    $response = $client->request('GET', $url, [
        'headers' => ['Accept-Encoding' => 'gzip, deflate'],
    ]);
    $content = decode_sitemap_content($response->getContent(), $url);

    $xml = simplexml_load_string($content);

    if (false === $xml) {
        throw new \RuntimeException(\sprintf('Invalid XML received from "%s".', $url));
    }

    return $xml;
}

function sitemap_basename(string $url): string
{
    $path = parse_url($url, \PHP_URL_PATH);

    if (!\is_string($path)) {
        return $url;
    }

    $basename = basename($path);

    if (str_ends_with(strtolower($basename), '.gz')) {
        $basename = substr($basename, 0, -3);
    }

    return $basename;
}

/**
 * @return string[]
 */
function extract_sitemap_urls(\SimpleXMLElement $xml): array
{
    $urls = [];

    foreach ($xml->sitemap as $sitemap) {
        $loc = trim((string) $sitemap->loc);

        if ('' !== $loc) {
            $urls[] = $loc;
        }
    }

    return $urls;
}

/**
 * @param array{total_urls: int, matched: int, rejected: array<string, int>, sample_rejection: ?string} $stats
 *
 * @return array{url: string, title: string, image: string}|null
 */
function finalize_sitemap_product_entry(
    string $productUrl,
    string $imageLoc,
    string $imageTitle,
    string $imageCaption,
    bool $requireProductUrl,
    bool $allowMissingImage,
    array &$stats,
): ?array {
    if ('' === $productUrl) {
        $stats['rejected']['empty_loc'] = ($stats['rejected']['empty_loc'] ?? 0) + 1;

        if (null === $stats['sample_rejection']) {
            $stats['sample_rejection'] = 'empty <loc>';
        }

        return null;
    }

    if ($requireProductUrl && !is_product_url($productUrl)) {
        $stats['rejected']['not_product_url'] = ($stats['rejected']['not_product_url'] ?? 0) + 1;

        return null;
    }

    if (is_category_url($productUrl)) {
        $stats['rejected']['category_url'] = ($stats['rejected']['category_url'] ?? 0) + 1;

        return null;
    }

    if ('' === $imageLoc) {
        if (!$allowMissingImage) {
            $stats['rejected']['missing_image_loc'] = ($stats['rejected']['missing_image_loc'] ?? 0) + 1;

            if (null === $stats['sample_rejection']) {
                $stats['sample_rejection'] = \sprintf('missing image:loc for %s', $productUrl);
            }

            return null;
        }

        $title = title_from_url_slug($productUrl);

        if ('' === $title) {
            $stats['rejected']['missing_title'] = ($stats['rejected']['missing_title'] ?? 0) + 1;

            if (null === $stats['sample_rejection']) {
                $stats['sample_rejection'] = \sprintf('missing title for %s', $productUrl);
            }

            return null;
        }

        ++$stats['matched'];
        $stats['accepted_without_image'] = ($stats['accepted_without_image'] ?? 0) + 1;

        return [
            'url' => $productUrl,
            'title' => $title,
            'image' => '',
        ];
    }

    $title = $imageTitle;

    if ('' === $title) {
        $title = $imageCaption;
    }

    if ('' === $title) {
        $title = title_from_url_slug($productUrl);
    }

    if ('' === $title) {
        $stats['rejected']['missing_title'] = ($stats['rejected']['missing_title'] ?? 0) + 1;

        if (null === $stats['sample_rejection']) {
            $stats['sample_rejection'] = \sprintf('missing title for %s', $productUrl);
        }

        return null;
    }

    ++$stats['matched'];

    return [
        'url' => $productUrl,
        'title' => $title,
        'image' => $imageLoc,
    ];
}

/**
 * @param array{total_urls: int, matched: int, rejected: array<string, int>, sample_rejection: ?string} $stats
 *
 * @return array{url: string, title: string, image: string}|null
 */
function extract_sitemap_product_from_url_node(
    \SimpleXMLElement $urlNode,
    bool $requireProductUrl,
    array &$stats,
    bool $allowMissingImage = false,
): ?array {
    $imageNamespace = 'http://www.google.com/schemas/sitemap-image/1.1';
    ++$stats['total_urls'];
    $images = $urlNode->children($imageNamespace);

    return finalize_sitemap_product_entry(
        trim((string) $urlNode->loc),
        trim((string) ($images->image->loc ?? '')),
        trim((string) ($images->image->title ?? '')),
        trim((string) ($images->image->caption ?? '')),
        $requireProductUrl,
        $allowMissingImage,
        $stats,
    );
}

/**
 * @param array{total_urls: int, matched: int, rejected: array<string, int>, sample_rejection: ?string} $stats
 *
 * @return array{url: string, title: string, image: string}|null
 */
function read_sitemap_url_product_from_reader(
    \XMLReader $reader,
    bool $requireProductUrl,
    array &$stats,
    bool $allowMissingImage = false,
): ?array {
    ++$stats['total_urls'];

    $productUrl = '';
    $imageLoc = '';
    $imageTitle = '';
    $imageCaption = '';
    $imageNamespace = 'http://www.google.com/schemas/sitemap-image/1.1';
    $sitemapNamespace = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    if ($reader->isEmptyElement) {
        return finalize_sitemap_product_entry('', '', '', '', $requireProductUrl, $allowMissingImage, $stats);
    }

    $depth = $reader->depth;

    while ($reader->read()) {
        if (\XMLReader::END_ELEMENT === $reader->nodeType && 'url' === $reader->localName && $reader->depth === $depth) {
            break;
        }

        if (\XMLReader::ELEMENT !== $reader->nodeType) {
            continue;
        }

        if ('loc' === $reader->localName && $sitemapNamespace === $reader->namespaceURI) {
            $productUrl = trim($reader->readString());

            continue;
        }

        if ('image' !== $reader->localName || $imageNamespace !== $reader->namespaceURI) {
            continue;
        }

        if ($reader->isEmptyElement) {
            continue;
        }

        $imageDepth = $reader->depth;

        while ($reader->read()) {
            if (\XMLReader::END_ELEMENT === $reader->nodeType && 'image' === $reader->localName && $reader->depth === $imageDepth) {
                break;
            }

            if (\XMLReader::ELEMENT !== $reader->nodeType || $imageNamespace !== $reader->namespaceURI) {
                continue;
            }

            if ('loc' === $reader->localName && '' === $imageLoc) {
                $imageLoc = trim($reader->readString());
            } elseif ('title' === $reader->localName && '' === $imageTitle) {
                $imageTitle = trim($reader->readString());
            } elseif ('caption' === $reader->localName && '' === $imageCaption) {
                $imageCaption = trim($reader->readString());
            }
        }
    }

    return finalize_sitemap_product_entry(
        $productUrl,
        $imageLoc,
        $imageTitle,
        $imageCaption,
        $requireProductUrl,
        $allowMissingImage,
        $stats,
    );
}

/**
 * @return array{products: array<string, array{url: string, title: string, image: string}>, stats: array{total_urls: int, matched: int, rejected: array<string, int>, sample_rejection: ?string}}
 */
function extract_sitemap_products_from_reader(\XMLReader $reader, bool $requireProductUrl = false, bool $allowMissingImage = false): array
{
    /** @var array<string, array{url: string, title: string, image: string}> $products */
    $products = [];
    $stats = [
        'total_urls' => 0,
        'matched' => 0,
        'rejected' => [],
        'sample_rejection' => null,
    ];

    while ($reader->read()) {
        if (\XMLReader::ELEMENT !== $reader->nodeType || 'url' !== $reader->localName) {
            continue;
        }

        $entry = read_sitemap_url_product_from_reader($reader, $requireProductUrl, $stats, $allowMissingImage);

        if (null !== $entry) {
            $products[$entry['url']] = $entry;
        }
    }

    return ['products' => $products, 'stats' => $stats];
}

function patch_sitemap_urlset_tag(string $urlsetTag): string
{
    if (str_contains($urlsetTag, 'xmlns:image=')) {
        return $urlsetTag;
    }

    return preg_replace(
        '/(<urlset\b[^>]*)(>)/',
        '$1 xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"$2',
        $urlsetTag,
        1,
    ) ?? $urlsetTag;
}

function ensure_sitemap_image_namespace(string $content): string
{
    if (str_contains($content, 'xmlns:image=')) {
        return $content;
    }

    if (!str_contains($content, '<urlset')) {
        return $content;
    }

    return patch_sitemap_urlset_tag($content);
}

function decompress_sitemap_to_temp_file(string $raw): string
{
    $tmpIn = tempnam(sys_get_temp_dir(), 'import_sitemap_in_');

    if (false === $tmpIn) {
        throw new \RuntimeException('Unable to create temp file for compressed sitemap input.');
    }

    $tmpOut = tempnam(sys_get_temp_dir(), 'import_sitemap_out_');

    if (false === $tmpOut) {
        unlink($tmpIn);

        throw new \RuntimeException('Unable to create temp file for decompressed sitemap output.');
    }

    file_put_contents($tmpIn, $raw);

    $in = @gzopen($tmpIn, 'rb');

    if (false === $in) {
        unlink($tmpIn);
        unlink($tmpOut);

        throw new \RuntimeException('Unable to open compressed sitemap for streaming decompression.');
    }

    $out = fopen($tmpOut, 'w');

    if (false === $out) {
        gzclose($in);
        unlink($tmpIn);
        unlink($tmpOut);

        throw new \RuntimeException('Unable to open decompressed sitemap output file.');
    }

    $headerBuffer = '';
    $headerWritten = false;

    while (!gzeof($in)) {
        $chunk = gzread($in, 65536);

        if (false === $chunk || '' === $chunk) {
            continue;
        }

        if ($headerWritten) {
            fwrite($out, $chunk);

            continue;
        }

        $headerBuffer .= $chunk;
        $urlsetStart = stripos($headerBuffer, '<urlset');

        if (false === $urlsetStart) {
            continue;
        }

        $urlsetEnd = strpos($headerBuffer, '>', $urlsetStart);

        if (false === $urlsetEnd) {
            continue;
        }

        $before = substr($headerBuffer, 0, $urlsetStart);
        $urlsetTag = substr($headerBuffer, $urlsetStart, $urlsetEnd - $urlsetStart + 1);
        $remainder = substr($headerBuffer, $urlsetEnd + 1);

        if ('' !== $before) {
            fwrite($out, $before);
        }

        fwrite($out, patch_sitemap_urlset_tag($urlsetTag));

        if ('' !== $remainder) {
            fwrite($out, $remainder);
        }

        $headerWritten = true;
        $headerBuffer = '';
    }

    if (!$headerWritten && '' !== $headerBuffer) {
        fwrite($out, ensure_sitemap_image_namespace($headerBuffer));
    }

    gzclose($in);
    fclose($out);
    unlink($tmpIn);

    return $tmpOut;
}

/**
 * @return array{reader: \XMLReader, tmpFile: ?string}
 */
function open_sitemap_reader(string $raw, string $url): array
{
    $path = parse_url($url, \PHP_URL_PATH) ?? '';
    $isGzFile = str_ends_with(strtolower($path), '.gz');
    $isGzContent = '' !== $raw && "\x1f\x8b" === substr($raw, 0, 2);
    $reader = new \XMLReader();
    $tmpFile = null;

    if ($isGzFile || $isGzContent) {
        $tmpFile = decompress_sitemap_to_temp_file($raw);

        if (!$reader->open($tmpFile)) {
            throw new \RuntimeException(\sprintf('Unable to open decompressed sitemap "%s".', $url));
        }
    } else {
        $content = ensure_sitemap_image_namespace(decode_sitemap_content($raw, $url));

        if (!$reader->XML($content)) {
            throw new \RuntimeException(\sprintf('Invalid XML received from "%s".', $url));
        }
    }

    return ['reader' => $reader, 'tmpFile' => $tmpFile];
}

function close_sitemap_reader(\XMLReader $reader, ?string $tmpFile): void
{
    $reader->close();

    if (null !== $tmpFile && file_exists($tmpFile)) {
        unlink($tmpFile);
    }
}

/**
 * @return array{products: array<string, array{url: string, title: string, image: string}>, stats: array{total_urls: int, matched: int, rejected: array<string, int>, sample_rejection: ?string}}
 */
function fetch_sitemap_product_extraction(
    \Symfony\Contracts\HttpClient\HttpClientInterface $client,
    string $url,
    bool $requireProductUrl = false,
    bool $allowMissingImage = false,
): array {
    $response = $client->request('GET', $url, [
        'headers' => ['Accept-Encoding' => 'gzip, deflate'],
    ]);
    ['reader' => $reader, 'tmpFile' => $tmpFile] = open_sitemap_reader($response->getContent(), $url);

    try {
        return extract_sitemap_products_from_reader($reader, $requireProductUrl, $allowMissingImage);
    } finally {
        close_sitemap_reader($reader, $tmpFile);
    }
}

/**
 * @return array{products: array<string, array{url: string, title: string, image: string}>, stats: array{total_urls: int, matched: int, rejected: array<string, int>, sample_rejection: ?string}}
 */
function extract_sitemap_products(\SimpleXMLElement $xml, bool $requireProductUrl = false, bool $allowMissingImage = false): array
{
    /** @var array<string, array{url: string, title: string, image: string}> $products */
    $products = [];
    $stats = [
        'total_urls' => 0,
        'matched' => 0,
        'rejected' => [],
        'sample_rejection' => null,
    ];

    foreach ($xml->url as $urlNode) {
        $entry = extract_sitemap_product_from_url_node($urlNode, $requireProductUrl, $stats, $allowMissingImage);

        if (null !== $entry) {
            $products[$entry['url']] = $entry;
        }
    }

    return ['products' => $products, 'stats' => $stats];
}

/**
 * @return array{products: array<string, array{url: string, title: string, image: string}>, stats: array{total_urls: int, matched: int, rejected: array<string, int>, sample_rejection: ?string}}
 */
function extract_sitemap_urls_by_pattern(\SimpleXMLElement $xml): array
{
    /** @var array<string, array{url: string, title: string, image: string}> $products */
    $products = [];
    $stats = [
        'total_urls' => 0,
        'matched' => 0,
        'rejected' => [],
        'sample_rejection' => null,
    ];

    foreach ($xml->url as $urlNode) {
        ++$stats['total_urls'];
        $productUrl = trim((string) $urlNode->loc);

        if ('' === $productUrl || !is_product_url($productUrl)) {
            $stats['rejected']['not_product_url'] = ($stats['rejected']['not_product_url'] ?? 0) + 1;

            continue;
        }

        $stats['rejected']['missing_image'] = ($stats['rejected']['missing_image'] ?? 0) + 1;

        if (null === $stats['sample_rejection']) {
            $stats['sample_rejection'] = \sprintf('product URL without image:loc: %s', $productUrl);
        }
    }

    return ['products' => $products, 'stats' => $stats];
}

/**
 * @param array<string, array{url: string, title: string, image: string}> $existing
 * @param array<string, array{url: string, title: string, image: string}> $new
 *
 * @return array<string, array{url: string, title: string, image: string}>
 */
function merge_sitemap_products(array $existing, array $new): array
{
    foreach ($new as $url => $product) {
        $existing[$url] = $product;
    }

    return $existing;
}

function normalize_import_image_url(string $url, string $baseUrl): string
{
    $url = trim(html_entity_decode($url));

    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }

    if (str_starts_with($url, '/')) {
        return rtrim($baseUrl, '/') . $url;
    }

    return $url;
}

/**
 * @return string[]
 */
function category_path_patterns(): array
{
    return [
        '#/collections/([^/]+)#',
        '#/catalogue/([^/]+)#',
        '#/categorie/([^/]+)#',
        '#/category/([^/]+)#',
        '#/shop/([^/]+)#',
    ];
}

function category_handle_from_href(string $href): string
{
    $path = parse_url($href, \PHP_URL_PATH) ?? $href;

    foreach (category_path_patterns() as $pattern) {
        if (preg_match($pattern, $path, $matches)) {
            return $matches[1];
        }
    }

    return '';
}

function collection_handle_from_href(string $href): string
{
    return category_handle_from_href($href);
}

/**
 * @param array<string, array{name: string, image?: string}> $byHandle
 */
function remember_category_collection(array &$byHandle, string $href, string $name, ?string $image = null): void
{
    $handle = category_handle_from_href($href);
    $name = trim(html_entity_decode(strip_tags($name)));

    if ('' === $handle || '' === $name) {
        return;
    }

    if (!isset($byHandle[$handle])) {
        $byHandle[$handle] = ['name' => $name];

        if (null !== $image && '' !== $image) {
            $byHandle[$handle]['image'] = $image;
        }

        return;
    }

    if (null !== $image && '' !== $image && !isset($byHandle[$handle]['image'])) {
        $byHandle[$handle]['image'] = $image;
    }
}

/**
 * @param array<string, array{name: string, image?: string}> $byHandle
 */
function remember_html_collection(array &$byHandle, string $href, string $name, ?string $image = null): void
{
    remember_category_collection($byHandle, $href, $name, $image);
}

/**
 * @return array<string, array{name: string, image?: string}>
 */
function extract_category_links_from_html(string $html, string $baseUrl): array
{
    /** @var array<string, array{name: string, image?: string}> $byHandle */
    $byHandle = [];

    $categoryHrefPattern = '~href="([^"]*(?:/collections/|/catalogue/|/categorie/|/category/|/shop/)[^"?]+)"~i';

    if (preg_match_all($categoryHrefPattern, $html, $hrefMatches, \PREG_SET_ORDER)) {
        foreach ($hrefMatches as $match) {
            $href = $match[1];
            $label = '';

            if (preg_match(
                '/href="' . preg_quote($href, '/') . '"[^>]*>([^<]+)/i',
                $html,
                $labelMatch,
            )) {
                $label = trim($labelMatch[1]);
            }

            if ('' === $label) {
                $label = humanize_slug(category_handle_from_href($href));
            }

            remember_category_collection($byHandle, $href, $label);
        }
    }

    if (preg_match_all(
        '/href="([^"]*\/collections\/[^"#?]+)"[^>]*class="[^"]*pk-thumbnails-collections__link[^"]*"[^>]*>.*?src="([^"]+)"[^>]*>.*?class="[^"]*pk-thumbnails-collections__text[^"]*"[^>]*>([^<]+)/is',
        $html,
        $matches,
        \PREG_SET_ORDER,
    )) {
        foreach ($matches as $match) {
            remember_category_collection(
                $byHandle,
                $match[1],
                $match[3],
                normalize_import_image_url($match[2], $baseUrl),
            );
        }
    }

    if (preg_match_all(
        '/href="([^"]*\/collections\/[^"#?]+)"[^>]*class="[^"]*nav[^"]*__link[^"]*"[^>]*>([^<]+)/i',
        $html,
        $matches,
        \PREG_SET_ORDER,
    )) {
        foreach ($matches as $match) {
            remember_category_collection($byHandle, $match[1], $match[2]);
        }
    }

    if (preg_match_all(
        '/href="([^"]*\/collections\/[^"#?]+)"[^>]*class="[^"]*collection[^"]*__link[^"]*"[^>]*>([^<]+)/i',
        $html,
        $matches,
        \PREG_SET_ORDER,
    )) {
        foreach ($matches as $match) {
            remember_category_collection($byHandle, $match[1], $match[2]);
        }
    }

    return $byHandle;
}

/**
 * @return array<string, array{name: string, image?: string}>
 */
function extract_collections_from_html(string $html, string $baseUrl): array
{
    return extract_category_links_from_html($html, $baseUrl);
}

/**
 * @param string[] $sitemapUrls
 *
 * @return array<string, array{name: string, image?: string}>
 */
function collection_from_catalog_url(string $url): ?array
{
    $path = parse_url($url, \PHP_URL_PATH);

    if (!\is_string($path)) {
        return null;
    }

    $path = strip_import_url_locale_prefix($path);

    if (!preg_match('#/products/(.+)$#', $path, $matches)) {
        return null;
    }

    $segments = array_values(array_filter(explode('/', trim($matches[1], '/')), static fn(string $segment): bool => '' !== $segment));

    if ([] === $segments) {
        return null;
    }

    $handle = end($segments);

    return [
        'handle' => $handle,
        'name' => humanize_slug($handle),
    ];
}

/**
 * @param array<string, array{name: string, image?: string}> $byHandle
 */
function extract_collections_from_sitemap_reader(\XMLReader $reader, bool $isCatalogSitemap, array &$byHandle, array &$stats): void
{
    while ($reader->read()) {
        if (\XMLReader::ELEMENT !== $reader->nodeType || 'loc' !== $reader->localName) {
            continue;
        }

        $loc = trim($reader->readString());

        if ('' === $loc) {
            continue;
        }

        if ($isCatalogSitemap) {
            $collection = collection_from_catalog_url($loc);

            if (null === $collection || isset($byHandle[$collection['handle']])) {
                continue;
            }

            $byHandle[$collection['handle']] = ['name' => $collection['name']];
            ++$stats['sitemap_category_links'];

            continue;
        }

        $handle = category_handle_from_href($loc);

        if ('' === $handle || isset($byHandle[$handle])) {
            continue;
        }

        remember_category_collection($byHandle, $loc, humanize_slug($handle));
        ++$stats['sitemap_category_links'];
    }
}

function extract_collections_from_sitemap(
    \Symfony\Contracts\HttpClient\HttpClientInterface $client,
    array $sitemapUrls,
    array &$stats,
): array {
    /** @var array<string, array{name: string, image?: string}> $byHandle */
    $byHandle = [];
    $stats['sitemap_category_links'] = 0;

    foreach ($sitemapUrls as $sitemapUrl) {
        try {
            $response = $client->request('GET', $sitemapUrl, [
                'headers' => ['Accept-Encoding' => 'gzip, deflate'],
            ]);
            ['reader' => $reader, 'tmpFile' => $tmpFile] = open_sitemap_reader($response->getContent(), $sitemapUrl);

            try {
                extract_collections_from_sitemap_reader(
                    $reader,
                    is_catalog_sitemap_url($sitemapUrl),
                    $byHandle,
                    $stats,
                );
            } finally {
                close_sitemap_reader($reader, $tmpFile);
            }
        } catch (\Throwable) {
            continue;
        }
    }

    return $byHandle;
}

/**
 * @param array<string, array{name: string, image?: string}> $existing
 * @param array<string, array{name: string, image?: string}> $new
 *
 * @return array<string, array{name: string, image?: string}>
 */
function merge_collections_by_handle(array $existing, array $new): array
{
    foreach ($new as $handle => $entry) {
        if (!isset($existing[$handle])) {
            $existing[$handle] = $entry;

            continue;
        }

        if (!isset($existing[$handle]['image']) && isset($entry['image'])) {
            $existing[$handle]['image'] = $entry['image'];
        }
    }

    return $existing;
}

function extract_category_links_for_ai(string $html): string
{
    $lines = ['Extracted category links from HTML:'];
    $seen = [];

    if (preg_match_all(
        '~href="([^"]*(?:/collections/|/catalogue/|/categorie/|/category/|/shop/)[^"?]+)"[^>]*>([^<]*)~i',
        $html,
        $matches,
        \PREG_SET_ORDER,
    )) {
        foreach ($matches as $match) {
            $href = trim($match[1]);
            $label = trim(html_entity_decode(strip_tags($match[2])));
            $key = category_handle_from_href($href);

            if ('' === $key || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            if ('' === $label) {
                $label = humanize_slug($key);
            }

            $lines[] = \sprintf('- %s (%s)', $label, $href);
        }
    }

    return implode("\n", $lines);
}

function extract_nav_html_snippet(string $html): string
{
    $snippets = [];

    if (preg_match_all(
        '/<(nav|header)\b[^>]*>.*?<\/\1>/is',
        $html,
        $matches,
    )) {
        foreach ($matches[0] as $snippet) {
            if (preg_match('#(?:catalogue|collection|category|categorie|shop/)#i', $snippet)) {
                $snippets[] = $snippet;
            }
        }
    }

    if ([] === $snippets) {
        return '';
    }

    return implode("\n", $snippets);
}

function prepare_html_for_ai_collections(string $html): string
{
    $parts = [extract_category_links_for_ai($html)];
    $navSnippet = extract_nav_html_snippet($html);

    if ('' !== $navSnippet) {
        $parts[] = clean_html_for_ai($navSnippet);
    }

    $prepared = implode("\n\n", array_filter($parts));

    if (\strlen($prepared) > HTML_MAX_LENGTH) {
        $prepared = substr($prepared, 0, HTML_MAX_LENGTH);
    }

    return $prepared;
}

/**
 * @param array<string, array{name: string, image?: string}> $byHandle
 * @param array<string, string>                              $imagesByHandle
 *
 * @return array<string, array{name: string, image?: string}>
 */
function enrich_collections_with_shopify_images(array $byHandle, array $imagesByHandle): array
{
    foreach ($byHandle as $handle => $entry) {
        if (isset($entry['image']) || !isset($imagesByHandle[$handle])) {
            continue;
        }

        $byHandle[$handle]['image'] = $imagesByHandle[$handle];
    }

    return $byHandle;
}

/**
 * @return array<string, string>
 */
function fetch_shopify_collection_images(\Symfony\Contracts\HttpClient\HttpClientInterface $client, string $baseUrl): array
{
    /** @var array<string, string> $imagesByHandle */
    $imagesByHandle = [];

    for ($page = 1; $page <= 20; ++$page) {
        try {
            $response = $client->request('GET', $baseUrl . '/collections.json', [
                'query' => [
                    'limit' => 250,
                    'page' => $page,
                ],
            ]);
            /** @var array<string, mixed> $payload */
            $payload = json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            break;
        }

        $collections = $payload['collections'] ?? null;

        if (!\is_array($collections) || [] === $collections) {
            break;
        }

        foreach ($collections as $collection) {
            if (!\is_array($collection)) {
                continue;
            }

            $handle = trim((string) ($collection['handle'] ?? ''));

            if ('' === $handle || isset($imagesByHandle[$handle])) {
                continue;
            }

            $image = $collection['image'] ?? null;
            $src = \is_array($image) ? ($image['src'] ?? null) : null;

            if (\is_string($src) && '' !== trim($src)) {
                $imagesByHandle[$handle] = trim($src);
            }
        }

        if (\count($collections) < 250) {
            break;
        }
    }

    return $imagesByHandle;
}

/**
 * @param array<string, array{name: string, image?: string}> $byHandle
 *
 * @return array<int, array{name: string, image?: string}>
 */
function normalize_collections_for_yaml(array $byHandle): array
{
    $collections = [];

    foreach ($byHandle as $entry) {
        $name = trim($entry['name']);

        if ('' === $name) {
            continue;
        }

        $row = ['name' => $name];

        if (isset($entry['image']) && \is_string($entry['image'])) {
            $image = trim($entry['image']);

            if ('' !== $image && 'null' !== strtolower($image)) {
                $row['image'] = $image;
            }
        }

        $collections[] = $row;
    }

    return $collections;
}

/**
 * @return array<int, array{name: string, image?: string}>
 */
function extract_collections_via_ai(string $preparedHtml): array
{
    import_log(\sprintf(
        'Preparing AI collection extraction (%d bytes of HTML)...',
        \strlen($preparedHtml),
    ));

    $platform = create_ai_platform();

    $messages = new MessageBag(
        Message::forSystem(
            <<<PROMPT
                Analyze the HTML of this e-commerce page.
                Extract all visible product categories/collections anywhere on the page (main content, grids, carousels, navigation, footer, etc.).
                Do not list individual products.
                For each category: name is mandatory; absolute image URL if present in the HTML (img src, srcset, background), otherwise null.
                Do not invent categories or images.
                Return JSON with a "collections" array of objects: {"name": string, "image": string|null}.
                PROMPT
        ),
        Message::ofUser($preparedHtml),
    );

    $content = invoke_ai_structured($platform, $messages, CollectionExtraction::class, 'Collection extraction');
    import_log('Parsing AI collection extraction response...');
    $extraction = deserialize_collection_extraction($content);

    /** @var array<string, array{name: string, image?: string}> $byHandle */
    $byHandle = [];

    foreach ($extraction->collections as $collection) {
        $name = trim($collection->name);

        if ('' === $name) {
            continue;
        }

        $key = normalize_label_for_matching($name);
        $entry = ['name' => $name];

        if (null !== $collection->image && '' !== trim($collection->image)) {
            $candidate = trim($collection->image);

            if ('null' !== strtolower($candidate)) {
                $entry['image'] = $candidate;
            }
        }

        $byHandle[$key] = $entry;
    }

    $collections = normalize_collections_for_yaml($byHandle);
    import_log(\sprintf('AI extracted %d collection(s).', \count($collections)));

    return $collections;
}

/**
 * @param array<string, mixed> $stats
 */
function log_sitemap_product_stats(string $sitemapUrl, array $stats, string $source): void
{
    $basename = sitemap_basename($sitemapUrl);
    $rejectedSummary = [] ;

    foreach ($stats['rejected'] ?? [] as $reason => $count) {
        $rejectedSummary[] = \sprintf('%s: %d', $reason, $count);
    }

    import_log(\sprintf(
        '  %s [%s] → %d URLs, %d matched%s',
        $basename,
        $source,
        $stats['total_urls'] ?? 0,
        $stats['matched'] ?? 0,
        [] === $rejectedSummary ? '' : ' (' . implode(', ', $rejectedSummary) . ')',
    ));

    if (isset($stats['sample_rejection']) && \is_string($stats['sample_rejection'])) {
        import_log('    sample rejection: ' . $stats['sample_rejection']);
    }
}

/**
 * @param array<string, mixed> $stats
 */
function log_fetch_source_report(array $context, array $stats): void
{
    import_log(\sprintf(
        'Platform detected: %s (score %d).',
        $context['platform'],
        $context['platform_score'],
    ));

    import_log('Products:');

    foreach ($stats['product_sitemaps'] ?? [] as $entry) {
        log_sitemap_product_stats($entry['url'], $entry['stats'], $entry['source']);
    }

    import_log(\sprintf(
        '  Total before dedup: %d product(s).',
        $stats['products_before_dedup'] ?? 0,
    ));
    import_log(\sprintf(
        '  Total after locale dedup: %d product(s).',
        $stats['products_after_locale_dedup'] ?? 0,
    ));

    import_log('Collections:');
    import_log(\sprintf('  html category links → %d', $stats['html_category_links'] ?? 0));
    import_log(\sprintf('  sitemap categories → %d', $stats['sitemap_category_links'] ?? 0));

    if (isset($stats['shopify_api_images']) && $stats['shopify_api_images'] > 0) {
        import_log(\sprintf('  shopify API images → %d', $stats['shopify_api_images']));
    }

    import_log(\sprintf('  merged (dedup) → %d', $stats['collections_merged'] ?? 0));
    import_log(\sprintf('  AI fallback → %s', $stats['collections_ai'] ?? 'skipped'));
}
