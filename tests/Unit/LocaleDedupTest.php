<?php

declare(strict_types=1);

namespace SyliusStarter\ImportFetch\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function SyliusStarter\ImportFetch\deduplicate_products_by_locale;
use function SyliusStarter\ImportFetch\detect_preferred_locale;
use function SyliusStarter\ImportFetch\product_locale_from_url;

final class LocaleDedupTest extends TestCase
{
    public function testProductLocaleFromUrl(): void
    {
        static::assertSame('fr', product_locale_from_url('https://shop.example.test/fr/products/chemise'));
        static::assertSame('en', product_locale_from_url('https://shop.example.test/en-gb/products/shirt'));
        static::assertNull(product_locale_from_url('https://shop.example.test/products/shirt'));
    }

    public function testDetectPreferredLocaleDefaultsToFirstPreference(): void
    {
        static::assertSame('de', detect_preferred_locale('https://shop.example.test/de/'));
        static::assertSame('fr', detect_preferred_locale('https://shop.example.test'));
    }

    public function testKeepsThePreferredLocaleVariantOfEachProduct(): void
    {
        $stats = [];
        $products = deduplicate_products_by_locale([
            'https://shop.example.test/products/shirt' => $this->product('https://shop.example.test/products/shirt'),
            'https://shop.example.test/fr/products/shirt' => $this->product('https://shop.example.test/fr/products/shirt'),
            'https://shop.example.test/de/products/shirt' => $this->product('https://shop.example.test/de/products/shirt'),
            'https://shop.example.test/en/products/hat' => $this->product('https://shop.example.test/en/products/hat'),
            'https://shop.example.test/products/hat' => $this->product('https://shop.example.test/products/hat'),
        ], 'de', $stats);

        static::assertSame([
            'https://shop.example.test/de/products/shirt',
            'https://shop.example.test/en/products/hat',
        ], array_keys($products));
        static::assertSame(2, $stats['products_after_locale_dedup']);
        static::assertSame(3, $stats['products_locale_dedup_removed']);
    }

    /**
     * @return array{url: string, title: string, image: string}
     */
    private function product(string $url): array
    {
        return ['url' => $url, 'title' => 'Title', 'image' => ''];
    }
}
