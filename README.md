# Castor Sylius Import Fetch

Private [Castor](https://castor.jolicode.com/) plugin: fetch an **existing storefront** (sitemap + HTML) into import YAML for [Sylius](https://sylius.com/), using the shared import stack from [`jeromegxj/castor-sylius`](https://github.com/jeromegxj/castor-sylius).

This package **does not** load fixtures into Sylius. It only produces YAML under `.castor/import/var/{project-slug}/`. The rest of the pipeline (`fixtures:generate`, `fixtures:load`, optional `var:push`) lives in **castor-sylius** — see its [README](https://github.com/jeromegxj/castor-sylius#e-commerce-import).

## Role in the stack

| Package | Responsibility |
|---------|----------------|
| **castor-sylius-import-fetch** (this repo) | `sylius:import:existing:fetch` → products + collections YAML |
| **castor-sylius** | Project slug resolution, import paths, AI helpers, fixture generation and load |
| **castor-api** (optional) | Async execution of the fetch task from the starter admin UI |

## Prerequisites

1. A Castor consumer project with `SyliusService` registered ([installation example](https://github.com/jeromegxj/castor-sylius#installation)).
2. **AI configuration** in `.env.local` at the project root when the storefront yields few collection links (fallback extraction). Same variables as AI import in castor-sylius (`AI_PROVIDER`, `AI_MODEL`, etc.) — see [castor-sylius README — Prerequisites](https://github.com/jeromegxj/castor-sylius#prerequisites).

## Installation

```bash
castor composer require jeromegxj/castor-sylius-import-fetch
```

Private packages require a GitHub token with **Read** on `jeromegxj/castor-sylius` and this repository (`COMPOSER_AUTH` or `~/.composer/auth.json`).

Register tasks in `castor.php` (optional guard if the plugin is not always installed):

```php
use Castor\Sylius\Service\SyliusService;
use Castor\Sylius\ImportFetch\Tasks\ExistingFetchTasks;

// inside register_service listener, after building SyliusService:
if (class_exists(ExistingFetchTasks::class)) {
    $syliusService = $syliusService
        ->withTasks((new ExistingFetchTasks('app', 'app'))())
    ;
}
```

Adjust `'app'` / directory to match your `SyliusService` name and Sylius app path.

## Command

| Task | Role |
|------|------|
| `sylius:import:existing:fetch` | Crawl storefront → write import YAML |

Exposed as an **async** Castor API task (`AsApi`) for long-running fetches from the sales-kit admin.

**Arguments and options**

| Name | Type | Description |
|------|------|-------------|
| `url` | argument | Storefront URL (required unless resolved via `--project`) |
| `--project` | option | Existing import project slug |
| `--name` | option | Display name for a new or updated project |
| `--description` | option | Short description stored in YAML metadata |
| `--project` + config | | URL can come from persisted project config when omitted |

Example:

```bash
castor sylius:import:existing:fetch https://www.example-store.test \
  --name="Example shop" \
  --description="Demo import"
```

## Output

Files are written under **`.castor/import/var/{project-slug}/`**:

| File | Content |
|------|---------|
| `{slug}` (YAML file) | `products` list + metadata |
| `collections` (YAML file) | `collections` list + same metadata |

Metadata includes `source`, detected `platform`, `mode: existing`, `name`, `description`, and `imported_at`.

**Next steps** (castor-sylius):

```bash
castor sylius:import:fixtures:generate existing --project=example-shop --limit=100
castor sylius:import:fixtures:load --project=example-shop
```

## How it works

1. **Products (step 1/2)** — Fetch homepage HTML, discover and resolve sitemaps, parse sub-sitemaps (image sitemaps and product URLs), deduplicate by locale, write products YAML and persist project config.
2. **Collections (step 2/2)** — Merge category links from HTML and sitemaps; on Shopify, enrich via `/collections.json`; if fewer collections than `IMPORT_COLLECTION_AI_THRESHOLD`, run compact AI extraction on cleaned homepage HTML.

Platform hint (e.g. Shopify) is inferred from homepage and sitemap URLs to tune parsing.

## HTTP client and production

Outgoing requests use Symfony HttpClient with **browser-like headers** (Chrome User-Agent, `Accept-Language`, standard `Accept`, etc.) via `import_fetch_http_client()` in [`src/http.php`](src/http.php). This reduces WAF false positives but does not guarantee access from datacenter IPs.

Some storefronts (Cloudflare, Shopify) return **HTTP 429** when fetched from production servers.

**Runbook:** run the fetch on your laptop, then push import var to the server:

```bash
castor sylius:import:existing:fetch https://www.example-store.test --name="Example" --project=example
castor sylius:import:var:push --project=example
```

Configure `IMPORT_VAR_PUSH_*` in `.env.local` — see [castor-sylius — Push import var to production](https://github.com/jeromegxj/castor-sylius#push-import-var-to-production).

## Development

This directory is a **Composer library**, not a Castor project: there is no `castor.php` here. Running `castor` in this folder may offer to initialize a project — that is expected.

- **Tests:** `composer install && ./vendor/bin/phpunit`
- **Run the task:** use a consumer such as [`castor-sylius`](../castor-sylius) or [`sylius-starter-sk`](../sylius-starter-sk) where `ExistingFetchTasks` is registered.
- **Dependency:** `jeromegxj/castor-sylius` via VCS; authenticate Composer against GitHub for private repos.

## License

MIT — see [`composer.json`](composer.json).
