<?php

declare(strict_types=1);

namespace Unit;

use PHPUnit\Framework\TestCase;

use function SyliusStarter\ImportFetch\category_handle_from_href;
use function SyliusStarter\ImportFetch\enrich_collections_with_shopify_data;
use function SyliusStarter\ImportFetch\extract_collections_from_sitemap_reader;

final class SitemapCollectionsTest extends TestCase
{
    public function testShopifyCollectionSitemapKeepsImagesOutOfCollections(): void
    {
        $byHandle = $this->extract(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
              <url>
                <loc>https://shop.example.test/collections/46-t-shirts-homme</loc>
                <image:image>
                  <image:loc>https://cdn.shopify.com/s/files/1/0564/collections/46_thumb_6310.jpg?v=1</image:loc>
                  <image:title>T-shirts homme</image:title>
                </image:image>
              </url>
              <url>
                <loc>https://shop.example.test/collections/bulle-50-homme</loc>
                <image:image>
                  <image:loc>https://cdn.shopify.com/s/files/1/0564/collections/bulle_50.jpg</image:loc>
                  <image:title>bulle_50_homme.jpg</image:title>
                </image:image>
              </url>
              <url>
                <loc>https://shop.example.test/collections/accessoires</loc>
              </url>
            </urlset>
            XML);

        static::assertSame([
            '46-t-shirts-homme' => [
                'name' => 'T-shirts homme',
                'image' => 'https://cdn.shopify.com/s/files/1/0564/collections/46_thumb_6310.jpg?v=1',
            ],
            'bulle-50-homme' => [
                'name' => 'Bulle 50 Homme',
                'image' => 'https://cdn.shopify.com/s/files/1/0564/collections/bulle_50.jpg',
            ],
            'accessoires' => ['name' => 'Accessoires'],
        ], $byHandle);
    }

    public function testImageFilesAreNotCategoryLinks(): void
    {
        static::assertSame('', category_handle_from_href('https://cdn.shopify.com/s/files/1/0564/collections/46_thumb.jpg?v=1'));
        static::assertSame('', category_handle_from_href('https://example.test/collections/banner.WEBP'));
    }

    public function testShopifyTitlesReplaceGuessedNames(): void
    {
        $byHandle = enrich_collections_with_shopify_data(
            [
                'sweats' => ['name' => 'Sweats', 'image' => 'https://example.test/sitemap.jpg'],
                'polos' => ['name' => 'Polos'],
            ],
            [
                'sweats' => ['title' => 'Sweats Homme', 'image' => 'https://example.test/api.jpg'],
                'polos' => ['image' => 'https://example.test/polos.jpg'],
            ],
        );

        static::assertSame([
            'sweats' => ['name' => 'Sweats Homme', 'image' => 'https://example.test/sitemap.jpg'],
            'polos' => ['name' => 'Polos', 'image' => 'https://example.test/polos.jpg'],
        ], $byHandle);
    }

    /**
     * @return array<string, array{name: string, image?: string}>
     */
    private function extract(string $xml): array
    {
        $reader = \XMLReader::XML($xml);
        static::assertInstanceOf(\XMLReader::class, $reader);

        $byHandle = [];
        $stats = ['sitemap_category_links' => 0];
        extract_collections_from_sitemap_reader($reader, false, $byHandle, $stats);

        return $byHandle;
    }
}
