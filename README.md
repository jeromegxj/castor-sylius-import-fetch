# jeromegxj/castor-sylius-import-fetch

Castor plugin: fetch existing shop data from sitemap into YAML, and rsync import var to a remote server (`sylius:import:var:push`).

Requires [`opencolibri/starter-core`](https://github.com/opencolibri/starter-core) and [`opencolibri/starter-import`](https://github.com/opencolibri/starter-import).

## Installation

```bash
castor composer require jeromegxj/castor-sylius-import-fetch
```

In `castor.php`:

```php
use OpenColibri\Starter\ImportFetch\Tasks\ImportFetchTasks;

$syliusService = $syliusService
    ->withTasks((new ImportFetchTasks('app', 'app'))())
;
```

Composer resolves public GitHub VCS repos (see consumer `castor.composer.json`).

## Development

```bash
composer install
composer ci      # PHPUnit + PHPStan + PHP CS Fixer (dry run)
composer cs:fix  # apply coding standards
```
