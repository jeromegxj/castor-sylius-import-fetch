<?php

declare(strict_types=1);

namespace SyliusStarter\ImportFetch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function SyliusStarter\ImportFetch\fetch_shopify_collections;
use function SyliusStarter\ImportFetch\merge_collections_by_handle;
use function SyliusStarter\ImportFetch\normalize_collections_for_yaml;

final class ShopifyCollectionsTest extends TestCase
{
    public function testFetchesEveryPageUntilAShortOne(): void
    {
        $firstPage = [];

        for ($i = 1; $i <= 250; ++$i) {
            $firstPage[] = ['handle' => 'collection-' . $i, 'title' => 'Collection ' . $i];
        }

        $firstPage[0] = [
            'handle' => 'collection-1',
            'title' => 'Sweats &amp; Hoodies',
            'image' => ['src' => ' https://cdn.example.test/sweats.jpg '],
        ];

        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$requests, $firstPage): MockResponse {
            $requests[] = $url;

            return new MockResponse(json_encode([
                'collections' => 1 === \count($requests) ? $firstPage : [
                    ['handle' => 'collection-1', 'title' => 'Duplicate'],
                    ['handle' => '', 'title' => 'No handle'],
                    ['handle' => 'last', 'image' => null],
                ],
            ], \JSON_THROW_ON_ERROR));
        });

        $byHandle = fetch_shopify_collections($client, 'https://shop.example.test');

        static::assertCount(2, $requests);
        static::assertStringContainsString('page=2', $requests[1]);
        static::assertCount(251, $byHandle);
        static::assertSame(['title' => 'Sweats & Hoodies', 'image' => 'https://cdn.example.test/sweats.jpg'], $byHandle['collection-1']);
        static::assertSame([], $byHandle['last']);
    }

    public function testStopsOnAnInvalidResponse(): void
    {
        $client = new MockHttpClient(new MockResponse('<html>Not Shopify</html>'));

        static::assertSame([], fetch_shopify_collections($client, 'https://shop.example.test'));
    }

    public function testMergeKeepsExistingNamesAndFillsMissingImages(): void
    {
        static::assertSame([
            'shirts' => ['name' => 'Shirts', 'image' => 'https://example.test/sitemap.jpg'],
            'hats' => ['name' => 'Hats', 'image' => 'https://example.test/hats.jpg'],
            'socks' => ['name' => 'Socks'],
        ], merge_collections_by_handle(
            [
                'shirts' => ['name' => 'Shirts', 'image' => 'https://example.test/sitemap.jpg'],
                'hats' => ['name' => 'Hats'],
            ],
            [
                'shirts' => ['name' => 'Other', 'image' => 'https://example.test/other.jpg'],
                'hats' => ['name' => 'Other hats', 'image' => 'https://example.test/hats.jpg'],
                'socks' => ['name' => 'Socks'],
            ],
        ));
    }

    public function testNormalizeForYamlDropsBlankNamesAndNullImages(): void
    {
        static::assertSame([
            ['name' => 'Shirts', 'image' => 'https://example.test/shirts.jpg'],
            ['name' => 'Hats'],
        ], normalize_collections_for_yaml([
            'shirts' => ['name' => ' Shirts ', 'image' => ' https://example.test/shirts.jpg '],
            'blank' => ['name' => '  '],
            'hats' => ['name' => 'Hats', 'image' => 'null'],
        ]));
    }
}
