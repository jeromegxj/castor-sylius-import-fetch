<?php

declare(strict_types=1);

namespace SyliusStarter\ImportFetch\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function SyliusStarter\ImportFetch\close_sitemap_reader;
use function SyliusStarter\ImportFetch\decode_sitemap_content;
use function SyliusStarter\ImportFetch\extract_sitemap_products_from_reader;
use function SyliusStarter\ImportFetch\open_sitemap_reader;

final class SitemapDecodingTest extends TestCase
{
    private const SITEMAP = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
          <url>
            <loc>https://shop.example.test/products/blue-shirt</loc>
            <image:image>
              <image:loc>https://cdn.example.test/blue-shirt.jpg</image:loc>
            </image:image>
          </url>
        </urlset>
        XML;

    public function testDecodesGzipContentDetectedByMagicBytes(): void
    {
        static::assertSame(self::SITEMAP, decode_sitemap_content($this->gzip(self::SITEMAP), 'https://shop.example.test/sitemap.xml'));
    }

    public function testLeavesPlainContentUntouched(): void
    {
        static::assertSame(self::SITEMAP, decode_sitemap_content(self::SITEMAP, 'https://shop.example.test/sitemap.xml.gz'));
    }

    public function testPlainSitemapGetsTheImageNamespace(): void
    {
        ['reader' => $reader, 'tmpFile' => $tmpFile] = open_sitemap_reader(self::SITEMAP, 'https://shop.example.test/sitemap.xml');

        static::assertNull($tmpFile);
        $this->assertReadsBlueShirt($reader);
        close_sitemap_reader($reader, $tmpFile);
    }

    public function testGzipSitemapIsStreamedThroughATemporaryFile(): void
    {
        ['reader' => $reader, 'tmpFile' => $tmpFile] = open_sitemap_reader($this->gzip(self::SITEMAP), 'https://shop.example.test/sitemap.xml.gz');

        static::assertIsString($tmpFile);
        static::assertFileExists($tmpFile);
        $this->assertReadsBlueShirt($reader);

        close_sitemap_reader($reader, $tmpFile);
        static::assertFileDoesNotExist($tmpFile);
    }

    public function testInvalidXmlIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid XML received from "https://shop.example.test/sitemap.xml".');

        open_sitemap_reader('', 'https://shop.example.test/sitemap.xml');
    }

    private function assertReadsBlueShirt(\XMLReader $reader): void
    {
        $extraction = extract_sitemap_products_from_reader($reader);

        static::assertSame(
            ['https://shop.example.test/products/blue-shirt'],
            array_keys($extraction['products']),
        );
        static::assertSame('https://cdn.example.test/blue-shirt.jpg', $extraction['products']['https://shop.example.test/products/blue-shirt']['image']);
    }

    private function gzip(string $content): string
    {
        $gzipped = gzencode($content);
        static::assertIsString($gzipped);

        return $gzipped;
    }
}
