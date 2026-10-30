<?php

declare(strict_types=1);

namespace SyliusStarter\ImportFetch\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function SyliusStarter\ImportFetch\collection_from_catalog_url;
use function SyliusStarter\ImportFetch\extract_collections_from_sitemap_reader;

final class SitemapCatalogCollectionsTest extends TestCase
{
    public function testCollectionFromCatalogUrlUsesLastProductsSegment(): void
    {
        static::assertSame(
            ['handle' => 'running-shoes', 'name' => 'Running Shoes'],
            collection_from_catalog_url('https://shop.example.test/products/sport/running-shoes'),
        );
    }

    public function testCollectionFromCatalogUrlStripsLocalePrefix(): void
    {
        static::assertSame(
            ['handle' => 'chemises', 'name' => 'Chemises'],
            collection_from_catalog_url('https://shop.example.test/fr/products/chemises/'),
        );
    }

    public function testCollectionFromCatalogUrlIgnoresOtherPaths(): void
    {
        static::assertNull(collection_from_catalog_url('https://shop.example.test/collections/chemises'));
        static::assertNull(collection_from_catalog_url('https://shop.example.test/products/'));
    }

    public function testCatalogSitemapRegistersEachCollectionOnce(): void
    {
        $reader = \XMLReader::XML(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
              <url><loc>https://shop.example.test/products/chemises</loc></url>
              <url><loc>https://shop.example.test/en/products/chemises</loc></url>
              <url><loc>https://shop.example.test/products/pulls-laine</loc></url>
              <url><loc>https://shop.example.test/about</loc></url>
            </urlset>
            XML);
        static::assertInstanceOf(\XMLReader::class, $reader);

        $byHandle = [];
        $stats = ['sitemap_category_links' => 0];
        extract_collections_from_sitemap_reader($reader, true, $byHandle, $stats);

        static::assertSame([
            'chemises' => ['name' => 'Chemises'],
            'pulls-laine' => ['name' => 'Pulls Laine'],
        ], $byHandle);
        static::assertSame(2, $stats['sitemap_category_links']);
    }
}
