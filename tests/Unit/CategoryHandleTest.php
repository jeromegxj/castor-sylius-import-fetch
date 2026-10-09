<?php

declare(strict_types=1);

namespace Unit;

use PHPUnit\Framework\TestCase;

use function Castor\Sylius\ImportFetch\category_handle_from_href;

final class CategoryHandleTest extends TestCase
{
    public function testCategoryHandleFromHrefRecognizesCommonPaths(): void
    {
        static::assertSame('outdoor', category_handle_from_href('https://example.test/collections/outdoor'));
        static::assertSame('shoes', category_handle_from_href('https://example.test/fr/categorie/shoes'));
        static::assertSame('', category_handle_from_href('https://example.test/products/foo'));
    }
}
