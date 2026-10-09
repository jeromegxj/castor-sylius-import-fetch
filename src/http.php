<?php

declare(strict_types=1);

namespace Castor\Sylius\ImportFetch;

use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Castor\http_client;

const IMPORT_FETCH_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

/**
 * @return array<string, string>
 */
function import_fetch_request_headers(): array
{
    return [
        'User-Agent' => IMPORT_FETCH_USER_AGENT,
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8',
        'Accept-Encoding' => 'gzip, deflate, br',
        'Upgrade-Insecure-Requests' => '1',
        'Cache-Control' => 'no-cache',
    ];
}

function import_fetch_http_client(): HttpClientInterface
{
    return http_client()->withOptions([
        'timeout' => 120,
        'headers' => import_fetch_request_headers(),
    ]);
}
