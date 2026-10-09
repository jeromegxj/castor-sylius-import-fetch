# jeromegxj/castor-sylius-import-fetch

Private Castor plugin: fetch existing shop data from sitemap into YAML, and rsync import var to a remote server (`sylius:import:var:push`).

Requires [`sylius-starter/sylius-starter`](https://github.com/sylius-starter/sylius-starter) (import + core) for shared import infrastructure.

## Installation

In your Castor project:

```bash
castor composer require jeromegxj/castor-sylius-import-fetch
```

Register tasks in `castor.php`:

```php
use SyliusStarter\Core\Service\SyliusService;
use SyliusStarter\ImportFetch\Tasks\ImportFetchTasks;

// inside register_service listener:
$syliusService = $syliusService
    ->withTasks((new ImportFetchTasks('app', 'app'))())
;
```

Then run `sylius:import:existing:fetch` to populate `.castor/import/var/{project-slug}/`.

## HTTP client (existing fetch)

Outgoing requests use Symfony HttpClient with browser-like headers to reduce WAF false positives. Some storefronts still return **HTTP 429** from datacenter IPs. If fetch fails in production, run the task locally, use **`sylius:import:var:push`**, then generate/load from the prod admin.

## Development (monorepo)

- **Composer**: VCS repo `https://github.com/sylius-starter/sylius-starter` in consumer `castor.composer.json`.
- **Run tasks**: use `sylius-starter-sk-2` or `castor-sylius` with `ImportFetchTasks` registered.
