<?php

declare(strict_types=1);

namespace SyliusStarter\ImportFetch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function SyliusStarter\ImportFetch\detect_import_platform;

final class PlatformDetectionTest extends TestCase
{
    public function testDetectsShopifyFromCollectionsEndpointAndProductSitemap(): void
    {
        $client = new MockHttpClient(new MockResponse('{"collections": []}'));

        static::assertSame(
            ['platform' => 'shopify', 'score' => 5],
            detect_import_platform($client, 'https://shop.example.test', '<html></html>', [
                'https://shop.example.test/sitemap_products_1.xml?from=1&to=2',
            ]),
        );
    }

    public function testDetectsShopifyFromHtmlMarkersWithoutApi(): void
    {
        $client = new MockHttpClient(new MockResponse('Not found', ['http_code' => 404]));

        static::assertSame(
            ['platform' => 'shopify', 'score' => 4],
            detect_import_platform($client, 'https://shop.example.test', '<script src="https://cdn.shopify.com/s/app.js"></script>', [
                'https://shop.example.test/sitemap_products_1.xml',
            ]),
        );
    }

    public function testFallsBackToGenericPlatform(): void
    {
        $client = new MockHttpClient(new MockResponse('Not found', ['http_code' => 404]));

        static::assertSame(
            ['platform' => 'generic', 'score' => 3],
            detect_import_platform($client, 'https://shop.example.test', '<a href="/catalogue/chemises">Chemises</a>', [
                'https://shop.example.test/sitemap.products.xml',
            ]),
        );
    }
}
