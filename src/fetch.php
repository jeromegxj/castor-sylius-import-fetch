<?php

declare(strict_types=1);

namespace Castor\Sylius\ImportFetch;

use Symfony\Component\Yaml\Yaml;

use const Castor\Sylius\Import\IMPORT_COLLECTION_AI_THRESHOLD;

use function Castor\Sylius\Import\ai_model;
use function Castor\Sylius\Import\ai_provider;
use function Castor\Sylius\Import\castor_var_dir;
use function Castor\Sylius\Import\castor_var_path;
use function Castor\Sylius\Import\ensure_castor_var_dir;
use function Castor\Sylius\Import\ensure_import_ai_ready;
use function Castor\Sylius\Import\import_log;
use function Castor\Sylius\Import\normalize_import_name;
use function Castor\Sylius\Import\parse_import_site_input;
use function Castor\Sylius\Import\persist_project_config;
use function Castor\Sylius\Import\taxon_code_from_name;
use function Castor\fs;
use function Castor\io;

function fetch_import_data(?string $url, ?string $projectName = null, ?string $description = null, ?string $projectSlug = null): void
{
    if (null === $url || '' === trim($url)) {
        io()->error('URL is required.');

        return;
    }

    if (null === $projectName || '' === trim($projectName)) {
        io()->error('Project name is required.');

        return;
    }

    try {
        ensure_import_ai_ready();
    } catch (\RuntimeException $exception) {
        io()->error($exception->getMessage());

        return;
    }

    try {
        $site = parse_import_site_input($url);
        $baseUrl = $site['base_url'];
        $siteHost = $site['host'];

        if (null !== $projectSlug && '' !== trim($projectSlug)) {
            $projectSlug = trim($projectSlug);
        } else {
            $projectSlug = normalize_import_name($projectName);
        }

        import_log(\sprintf(
            'Environment loaded — AI provider: %s, model: %s.',
            ai_provider(),
            ai_model(),
        ));
    } catch (\InvalidArgumentException|\RuntimeException $exception) {
        io()->error($exception->getMessage());

        return;
    }

    if ($url !== $baseUrl) {
        import_log(\sprintf('Normalized site input "%s" → %s', trim($url), $baseUrl));
    }

    ensure_castor_var_dir();
    import_log(\sprintf('Import var directory ready: %s', castor_var_dir()));

    $client = import_fetch_http_client();

    io()->title(\sprintf('Fetching import data from %s', $baseUrl));
    import_log(\sprintf('Project slug: %s (source host: %s)', $projectSlug, $siteHost));

    import_log(\sprintf('Fetching homepage HTML: %s', $baseUrl));

    try {
        $html = $client->request('GET', $baseUrl)->getContent();
        import_log(\sprintf('Homepage fetched: %d bytes.', \strlen($html)));
    } catch (\Throwable $exception) {
        io()->error(\sprintf('Failed to fetch page "%s": %s', $baseUrl, $exception->getMessage()));

        return;
    }

    io()->section('Step 1/2 — Products from sitemap');

    $sitemapCandidates = discover_sitemap_candidate_urls($client, $baseUrl, $html);
    import_log(\sprintf('Discovered %d sitemap candidate(s).', \count($sitemapCandidates)));

    $resolvedSitemap = resolve_main_sitemap($client, $sitemapCandidates);
    $mainSitemap = $resolvedSitemap['xml'];
    $sitemapUrl = $resolvedSitemap['url'];
    $sitemapUrls = [];

    if (null === $mainSitemap || null === $sitemapUrl) {
        io()->error('No sitemap could be fetched. Import aborted.');

        foreach ($resolvedSitemap['failures'] as $failedUrl => $message) {
            import_log(\sprintf('  failed: %s → %s', $failedUrl, $message));
        }

        return;
    }

    import_log(\sprintf('Main sitemap resolved: %s', $sitemapUrl));
    $sitemapUrls = extract_sitemap_urls($mainSitemap);

    $platformDetection = detect_import_platform($client, $baseUrl, $html, $sitemapUrls);
    /** @var array<string, mixed> $fetchStats */
    $fetchStats = [
        'product_sitemaps' => [],
        'products_before_dedup' => 0,
    ];
    $context = create_import_context(
        $baseUrl,
        $projectSlug,
        $platformDetection['platform'],
        $platformDetection['score'],
        $fetchStats,
    );

    /** @var array<string, array{url: string, title: string, image: string}> $products */
    $products = [];

    if (null !== $mainSitemap && null !== $sitemapUrl) {
        if ([] !== $sitemapUrls) {
            $sitemapUrls = sort_sitemap_urls_by_priority($sitemapUrls, $context['platform']);
            io()->info(\sprintf('Found %d sub-sitemaps.', \count($sitemapUrls)));
            if (stream_isatty(\STDOUT)) {
                io()->progressStart(\count($sitemapUrls));
            }

            $skippedSitemaps = [];

            foreach ($sitemapUrls as $subSitemapUrl) {
                if (is_auxiliary_sitemap_url($subSitemapUrl)) {
                    if (stream_isatty(\STDOUT)) {
                        io()->progressAdvance();
                    }

                    continue;
                }

                try {
                    $requireProductUrl = !is_product_sitemap_url($subSitemapUrl);
                    $allowMissingImage = is_product_sitemap_url($subSitemapUrl);
                    $extraction = fetch_sitemap_product_extraction(
                        $client,
                        $subSitemapUrl,
                        $requireProductUrl,
                        $allowMissingImage,
                    );
                    $products = merge_sitemap_products($products, $extraction['products']);
                    $fetchStats['product_sitemaps'][] = [
                        'url' => $subSitemapUrl,
                        'source' => 'image_sitemap',
                        'stats' => $extraction['stats'],
                    ];
                } catch (\Throwable $exception) {
                    $skippedSitemaps[$subSitemapUrl] = $exception->getMessage();
                }

                if (stream_isatty(\STDOUT)) {
                    io()->progressAdvance();
                }
            }

            if (stream_isatty(\STDOUT)) {
                io()->progressFinish();
            }

            foreach ($skippedSitemaps as $subSitemapUrl => $message) {
                io()->warning(\sprintf('Skipping sub-sitemap "%s": %s', $subSitemapUrl, $message));
            }
        } else {
            import_log('No sub-sitemaps found in main sitemap.');
        }

        $mainExtraction = extract_sitemap_products($mainSitemap, true);
        $products = merge_sitemap_products($products, $mainExtraction['products']);
        $fetchStats['product_sitemaps'][] = [
            'url' => $sitemapUrl,
            'source' => 'main_sitemap',
            'stats' => $mainExtraction['stats'],
        ];
    }

    $fetchStats['products_before_dedup'] = \count($products);
    $products = deduplicate_products_by_locale($products, $context['locale'], $fetchStats);

    $productsPath = castor_var_path($projectSlug);
    import_log(\sprintf('Writing products YAML: %s', $productsPath));

    $yamlMetadata = [
        'source' => $baseUrl,
        'platform' => $context['platform'],
        'mode' => 'existing',
        'name' => trim($projectName),
        'description' => trim((string) $description),
        'imported_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
    ];

    fs()->dumpFile(
        $productsPath,
        Yaml::dump(
            $yamlMetadata + ['products' => array_values($products)],
            4,
            4,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK,
        ),
    );

    persist_project_config($projectSlug, $yamlMetadata);

    if (0 === \count($products)) {
        io()->warning(\sprintf(
            'Imported 0 products to %s. Check sitemap discovery and format (image:loc preferred; product sitemaps accept URL slug titles).',
            $productsPath,
        ));
    } else {
        io()->success(\sprintf('Imported %d products to %s', \count($products), $productsPath));
    }

    io()->section('Step 2/2 — Collections');

    import_log('Parsing category links from HTML...');
    $collectionsByHandle = extract_category_links_from_html($html, $baseUrl);
    $fetchStats['html_category_links'] = \count($collectionsByHandle);

    import_log('Parsing category links from sitemaps...');
    $allSitemapUrls = [] !== $sitemapUrls ? $sitemapUrls : (null !== $sitemapUrl ? [$sitemapUrl] : []);
    $sitemapCollections = extract_collections_from_sitemap($client, $allSitemapUrls, $fetchStats);
    $collectionsByHandle = merge_collections_by_handle($collectionsByHandle, $sitemapCollections);
    $fetchStats['collections_merged'] = \count($collectionsByHandle);

    if ('shopify' === $context['platform']) {
        import_log('Shopify detected — enriching collections via /collections.json...');
        $shopifyImages = fetch_shopify_collection_images($client, $baseUrl);
        $fetchStats['shopify_api_images'] = \count($shopifyImages);

        if ([] !== $shopifyImages) {
            import_log(\sprintf('Enriching collections with %d Shopify image(s)...', \count($shopifyImages)));
            $collectionsByHandle = enrich_collections_with_shopify_images($collectionsByHandle, $shopifyImages);
        }
    }

    if (\count($collectionsByHandle) < IMPORT_COLLECTION_AI_THRESHOLD) {
        import_log(\sprintf(
            'Only %d collection(s) found — falling back to compact AI extraction.',
            \count($collectionsByHandle),
        ));
        $fetchStats['collections_ai'] = 'used';

        $preparedHtml = prepare_html_for_ai_collections($html);
        import_log(\sprintf('Prepared compact AI payload: %d bytes.', \strlen($preparedHtml)));

        try {
            $aiCollections = extract_collections_via_ai($preparedHtml);

            foreach ($aiCollections as $collection) {
                $name = trim($collection['name']);

                if ('' === $name) {
                    continue;
                }

                $handle = taxon_code_from_name($name);

                if (!isset($collectionsByHandle[$handle])) {
                    $collectionsByHandle[$handle] = $collection;
                } elseif (!isset($collectionsByHandle[$handle]['image']) && isset($collection['image'])) {
                    $collectionsByHandle[$handle]['image'] = $collection['image'];
                }
            }

            $fetchStats['collections_merged'] = \count($collectionsByHandle);
        } catch (\Throwable $exception) {
            io()->error(\sprintf('AI collection extraction failed: %s', $exception->getMessage()));

            if ([] === $collectionsByHandle) {
                return;
            }
        }
    } else {
        $fetchStats['collections_ai'] = 'skipped (sufficient)';
        import_log('Sufficient collections found — skipping AI extraction.');
    }

    $collections = normalize_collections_for_yaml($collectionsByHandle);

    if ([] === $collections) {
        io()->error('No collections could be extracted from the page.');

        return;
    }

    $collectionsPath = castor_var_path($projectSlug, 'collections');
    import_log(\sprintf('Writing collections YAML: %s', $collectionsPath));

    fs()->dumpFile(
        $collectionsPath,
        Yaml::dump(
            $yamlMetadata + ['collections' => $collections],
            4,
            4,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK,
        ),
    );

    import_log(\sprintf('  merged (dedup) → %d', \count($collections)));
    import_log(\sprintf('  AI fallback → %s', $fetchStats['collections_ai'] ?? 'skipped'));

    log_fetch_source_report($context, $fetchStats);

    io()->success(\sprintf(
        'Extracted %d collections to %s',
        \count($collections),
        $collectionsPath,
    ));
}
