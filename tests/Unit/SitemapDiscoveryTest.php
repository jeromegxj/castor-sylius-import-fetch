<?php

declare(strict_types=1);

namespace Unit;

use PHPUnit\Framework\TestCase;

use function SyliusStarter\ImportFetch\extract_sitemap_urls_from_robots;
use function SyliusStarter\ImportFetch\sitemap_folder_fallback_urls;

final class SitemapDiscoveryTest extends TestCase
{
    public function testExtractsSitemapsFromRobots(): void
    {
        $robots = <<<'TXT'
            User-agent: *
            Disallow: /checkout/

            # Reference both language sitemaps
            Sitemap: https://www.pros-pro.com/sitemap_pros_pro_de.xml
            Sitemap: https://www.pros-pro.com/sitemap_pros_pro_en.xml
            TXT;

        static::assertSame([
            'https://www.pros-pro.com/sitemap_pros_pro_de.xml',
            'https://www.pros-pro.com/sitemap_pros_pro_en.xml',
        ], extract_sitemap_urls_from_robots($robots));
    }

    public function testFallsBackToMagentoSitemapFolder(): void
    {
        static::assertSame([
            'https://www.pros-pro.com/sitemap/sitemap_pros_pro_de.xml',
            'https://www.pros-pro.com/sitemap/sitemap_pros_pro_en.xml',
        ], sitemap_folder_fallback_urls([
            'https://www.pros-pro.com/sitemap_pros_pro_de.xml',
            'https://www.pros-pro.com/sitemap_pros_pro_en.xml',
        ], 'https://www.pros-pro.com'));
    }

    public function testSkipsUrlsAlreadyInSitemapFolderOrOnAnotherHost(): void
    {
        static::assertSame([], sitemap_folder_fallback_urls([
            'https://shop.example.test/sitemap/sitemap.xml',
            'https://cdn.example.test/sitemap.xml',
        ], 'https://shop.example.test'));
    }
}
