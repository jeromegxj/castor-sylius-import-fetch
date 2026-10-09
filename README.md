# jeromegxj/castor-sylius-import-fetch

Private Castor plugin: fetch existing shop data from sitemap into YAML.

Requires [`jeromegxj/castor-sylius`](https://github.com/jeromegxj/castor-sylius) for shared import infrastructure (paths, project config, AI helpers).

## Installation

In your Castor project:

```bash
castor composer require jeromegxj/castor-sylius-import-fetch
```

Register tasks in `castor.php`:

```php
use Castor\Sylius\ImportFetch\Tasks\ExistingFetchTasks;

// inside register_service listener:
$syliusService = $syliusService
    ->withTasks((new ExistingFetchTasks('app', 'app'))())
;
```

Then run `sylius:import:existing:fetch` to populate `.castor/import/var/{project-slug}/`.

## Development (monorepo)

This package is a **Castor library plugin**, not a Castor project: there is no `castor.php` here. Running `castor` in this directory will offer to initialize a new project — that is expected.

- **Composer**: declare a VCS repository for `https://github.com/jeromegxj/castor-sylius` and authenticate with a GitHub token (`COMPOSER_AUTH` or `~/.composer/auth.json`).
- **Run tasks**: use a consumer project such as [`castor-sylius`](../castor-sylius) or [`sylius-starter-sk`](../sylius-starter-sk) where `castor.php` registers `ExistingFetchTasks`.
