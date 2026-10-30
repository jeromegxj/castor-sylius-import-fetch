<?php

declare(strict_types=1);

namespace SyliusStarter\ImportFetch\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function SyliusStarter\ImportFetch\extract_sitemap_urls_from_html;
use function SyliusStarter\ImportFetch\is_auxiliary_sitemap_url;
use function SyliusStarter\ImportFetch\is_catalog_sitemap_url;
use function SyliusStarter\ImportFetch\is_product_sitemap_url;
use function SyliusStarter\ImportFetch\sort_sitemap_urls_by_priority;

final class SitemapPriorityTest extends TestCase
{
    public function testProductSitemapsComeFirstForThePlatform(): void
    {
        $urls = [
            'https://shop.example.test/sitemap_pages_1.xml',
            'https://shop.example.test/sitemap_products_1.xml',
            'https://shop.example.test/sitemap.products.xml',
        ];

        static::assertSame([
            'https://shop.example.test/sitemap_products_1.xml',
            'https://shop.example.test/sitemap.products.xml',
            'https://shop.example.test/sitemap_pages_1.xml',
        ], sort_sitemap_urls_by_priority($urls, 'shopify'));
        static::assertSame([
            'https://shop.example.test/sitemap.products.xml',
            'https://shop.example.test/sitemap_products_1.xml',
            'https://shop.example.test/sitemap_pages_1.xml',
        ], sort_sitemap_urls_by_priority($urls, 'generic'));
    }

    public function testSitemapKinds(): void
    {
        static::assertTrue(is_product_sitemap_url('https://shop.example.test/sitemap_products_1.xml'));
        static::assertTrue(is_product_sitemap_url('https://shop.example.test/products-sitemap.xml'));
        static::assertFalse(is_product_sitemap_url('https://shop.example.test/sitemap_pages_1.xml'));

        static::assertTrue(is_catalog_sitemap_url('https://shop.example.test/sitemap-catalog.xml'));
        static::assertTrue(is_auxiliary_sitemap_url('https://shop.example.test/sitemap-catalog.xml'));
        static::assertTrue(is_auxiliary_sitemap_url('https://shop.example.test/sitemap-stories.xml'));
        static::assertFalse(is_auxiliary_sitemap_url('https://shop.example.test/sitemap-products.xml'));
    }

    public function testExtractsSitemapLinksFromHtml(): void
    {
        $html = <<<'HTML'
            <link rel="sitemap" type="application/xml" href="/sitemap.xml">
            <link rel='sitemap' href='//cdn.example.test/sitemap.xml'>
            <link rel="sitemap" href="/sitemap.xml">
            <link rel="stylesheet" href="/style.css">
            HTML;

        static::assertSame([
            'https://shop.example.test/sitemap.xml',
            'https://cdn.example.test/sitemap.xml',
        ], extract_sitemap_urls_from_html($html, 'https://shop.example.test/'));
    }
}
