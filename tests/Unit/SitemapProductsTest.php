<?php

declare(strict_types=1);

namespace SyliusStarter\ImportFetch\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function SyliusStarter\ImportFetch\ensure_sitemap_image_namespace;
use function SyliusStarter\ImportFetch\extract_sitemap_products_from_reader;
use function SyliusStarter\ImportFetch\patch_sitemap_urlset_tag;

final class SitemapProductsTest extends TestCase
{
    private const SITEMAP = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
          <url>
            <loc>https://shop.example.test/products/blue-shirt</loc>
            <image:image>
              <image:loc>https://cdn.example.test/blue-shirt.jpg</image:loc>
              <image:title>Blue shirt</image:title>
            </image:image>
          </url>
          <url>
            <loc>https://shop.example.test/products/red-scarf</loc>
            <image:image>
              <image:loc>https://cdn.example.test/red-scarf.jpg</image:loc>
              <image:caption>Red scarf</image:caption>
            </image:image>
          </url>
          <url>
            <loc>https://shop.example.test/products/green-hat</loc>
          </url>
          <url>
            <loc>https://shop.example.test/about-us</loc>
            <image:image>
              <image:loc>https://cdn.example.test/team.jpg</image:loc>
            </image:image>
          </url>
          <url>
            <loc>https://shop.example.test/collections/shirts</loc>
            <image:image>
              <image:loc>https://cdn.example.test/shirts.jpg</image:loc>
            </image:image>
          </url>
        </urlset>
        XML;

    public function testKeepsProductsWithImagesAndUsesTitleThenCaption(): void
    {
        $extraction = $this->extract(false, false);

        static::assertSame([
            'https://shop.example.test/products/blue-shirt' => [
                'url' => 'https://shop.example.test/products/blue-shirt',
                'title' => 'Blue shirt',
                'image' => 'https://cdn.example.test/blue-shirt.jpg',
            ],
            'https://shop.example.test/products/red-scarf' => [
                'url' => 'https://shop.example.test/products/red-scarf',
                'title' => 'Red scarf',
                'image' => 'https://cdn.example.test/red-scarf.jpg',
            ],
            'https://shop.example.test/about-us' => [
                'url' => 'https://shop.example.test/about-us',
                'title' => 'About Us',
                'image' => 'https://cdn.example.test/team.jpg',
            ],
        ], $extraction['products']);
        static::assertSame(5, $extraction['stats']['total_urls']);
        static::assertSame(3, $extraction['stats']['matched']);
        static::assertSame(['missing_image_loc' => 1, 'category_url' => 1], $extraction['stats']['rejected']);
        static::assertSame('missing image:loc for https://shop.example.test/products/green-hat', $extraction['stats']['sample_rejection']);
    }

    public function testRequireProductUrlRejectsOtherPages(): void
    {
        $extraction = $this->extract(true, false);

        static::assertArrayNotHasKey('https://shop.example.test/about-us', $extraction['products']);
        static::assertSame(2, $extraction['stats']['rejected']['not_product_url']);
    }

    public function testAllowMissingImageFallsBackToUrlSlugTitle(): void
    {
        $extraction = $this->extract(true, true);

        static::assertSame([
            'url' => 'https://shop.example.test/products/green-hat',
            'title' => 'Green Hat',
            'image' => '',
        ], $extraction['products']['https://shop.example.test/products/green-hat']);
        static::assertSame(1, $extraction['stats']['accepted_without_image'] ?? null);
    }

    public function testAddsMissingImageNamespaceToUrlset(): void
    {
        $patched = ensure_sitemap_image_namespace('<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url/></urlset>');

        static::assertStringContainsString(
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">',
            $patched,
        );
        static::assertSame('<sitemapindex/>', ensure_sitemap_image_namespace('<sitemapindex/>'));

        $declared = '<urlset xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';
        static::assertSame($declared, patch_sitemap_urlset_tag($declared));
    }

    /**
     * @return array{products: array<string, array{url: string, title: string, image: string}>, stats: array<string, mixed>}
     */
    private function extract(bool $requireProductUrl, bool $allowMissingImage): array
    {
        $reader = \XMLReader::XML(self::SITEMAP);
        static::assertInstanceOf(\XMLReader::class, $reader);

        return extract_sitemap_products_from_reader($reader, $requireProductUrl, $allowMissingImage);
    }
}
