<?php

declare(strict_types=1);

namespace Castor\Sylius\ImportFetch\Tasks;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Castor\Sylius\App;
use Castor\Sylius\Import\ImportContext;
use Castor\Api\Attribute\AsApi;

use function Castor\io;
use function Castor\Sylius\Import\parse_import_site_input;
use function Castor\Sylius\Import\resolve_import_project;
use function Castor\Sylius\ImportFetch\fetch_import_data;

final class ExistingFetchTasks
{
    public function __construct(
        private readonly string $name,
        private readonly string $directory,
    ) {}

    /**
     * @return iterable<int, array{task: AsTask, function: callable}>
     */
    public function __invoke(): iterable
    {
        yield from $this->fetchTask();
    }

    private function withContext(callable $callback): void
    {
        ImportContext::setCurrent(new ImportContext(new App($this->name, $this->directory), $this->name));
        $callback();
    }

    /**
     * @return iterable<int, array{task: AsTask, function: callable}>
     */
    private function fetchTask(): iterable
    {
        yield [
            'task' => new AsTask('fetch', 'sylius:import:existing', 'Fetch products from sitemap and collections into YAML files'),
            'function' => #[AsApi(async: true)] function (
                #[AsArgument]
                ?string $url = null,
                #[AsOption]
                ?string $project = null,
                #[AsOption]
                ?string $name = null,
                #[AsOption]
                ?string $description = null,
            ): void {
                $this->withContext(static function () use ($url, $project, $name, $description): void {
                    try {
                        $resolved = resolve_import_project('existing', $project, $name, $description, $url);
                    } catch (\RuntimeException $exception) {
                        io()->error($exception->getMessage());

                        return;
                    }

                    $resolvedName = $resolved['name'];
                    $resolvedDescription = $resolved['description'];
                    $resolvedUrl = $resolved['url'];
                    $resolvedSlug = $resolved['slug'];

                    if (null === $resolvedUrl || '' === trim($resolvedUrl)) {
                        io()->error('URL is required.');

                        return;
                    }

                    try {
                        $site = parse_import_site_input($resolvedUrl);

                        if (trim($resolvedUrl) !== $site['base_url']) {
                            io()->comment(\sprintf('Normalized URL: %s', $site['base_url']));
                        }

                        $resolvedUrl = $site['base_url'];
                    } catch (\InvalidArgumentException $exception) {
                        io()->error($exception->getMessage());

                        return;
                    }

                    fetch_import_data($resolvedUrl, $resolvedName, $resolvedDescription, $resolvedSlug);
                });
            },
        ];
    }
}
