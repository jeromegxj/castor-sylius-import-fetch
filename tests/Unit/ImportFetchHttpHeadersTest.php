<?php

declare(strict_types=1);

namespace Unit;

use PHPUnit\Framework\TestCase;

use function SyliusStarter\ImportFetch\import_fetch_request_headers;

final class ImportFetchHttpHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/src/http.php';
    }

    public function testUserAgentLooksLikeBrowserNotImportBot(): void
    {
        $headers = import_fetch_request_headers();

        static::assertArrayHasKey('User-Agent', $headers);
        static::assertStringNotContainsString('sylius-starter-import', $headers['User-Agent']);
        static::assertStringContainsString('Chrome', $headers['User-Agent']);
    }

    public function testAcceptLanguageIsFrenchFixed(): void
    {
        $headers = import_fetch_request_headers();

        static::assertSame('fr-FR,fr;q=0.9,en;q=0.8', $headers['Accept-Language']);
    }

    public function testIncludesAcceptAndUpgradeInsecureRequests(): void
    {
        $headers = import_fetch_request_headers();

        static::assertArrayHasKey('Accept', $headers);
        static::assertStringContainsString('text/html', $headers['Accept']);
        static::assertSame('1', $headers['Upgrade-Insecure-Requests']);
    }
}
