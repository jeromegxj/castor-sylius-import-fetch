# jeromegxj/castor-sylius-import-fetch

Castor plugin: fetch existing shop data from sitemap into YAML, and rsync import var to a remote server (`sylius:import:var:push`).

Requires [`sylius-starter/core`](https://github.com/sylius-starter/sylius-starter-core) and [`sylius-starter/import`](https://github.com/sylius-starter/sylius-starter-import).

## Installation

```bash
castor composer require jeromegxj/castor-sylius-import-fetch
```

In `castor.php`:

```php
use SyliusStarter\ImportFetch\Tasks\ImportFetchTasks;

$syliusService = $syliusService
    ->withTasks((new ImportFetchTasks('app', 'app'))())
;
```

Composer resolves public GitHub VCS repos (see consumer `castor.composer.json`).
