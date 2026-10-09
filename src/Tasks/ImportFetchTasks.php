<?php

declare(strict_types=1);

namespace SyliusStarter\ImportFetch\Tasks;

use Castor\Api\Attribute\AsApi;
use Castor\Attribute\AsArgument;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use SyliusStarter\Core\App;
use SyliusStarter\Import\ImportContext;

use function Castor\io;
use function SyliusStarter\Import\import_log;
use function SyliusStarter\Import\parse_import_site_input;
use function SyliusStarter\Import\resolve_cli_project_slug;
use function SyliusStarter\Import\resolve_import_project;
use function SyliusStarter\ImportFetch\fetch_import_data;
use function SyliusStarter\ImportFetch\push_import_var_to_remote;

final class ImportFetchTasks
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
        yield from $this->varPushTask();
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

    /**
     * @return iterable<int, array{task: AsTask, function: callable}>
     */
    private function varPushTask(): iterable
    {
        yield [
            'task' => new AsTask('push', 'sylius:import:var', 'Rsync local import var for one shop to a remote server (SSH)'),
            'function' => function (
                #[AsOption]
                ?string $project = null,
                #[AsOption]
                bool $dryRun = false,
                #[AsOption]
                bool $delete = false,
            ): void {
                $this->withContext(function () use ($project, $dryRun, $delete): void {
                    if (null === $project || '' === trim($project)) {
                        io()->error('Project slug is required. Pass --project.');

                        return;
                    }

                    try {
                        $original = trim($project);
                        $projectSlug = resolve_cli_project_slug($original);

                        if ($original !== $projectSlug) {
                            import_log(\sprintf('Using project slug: %s', $projectSlug));
                        }
                    } catch (\InvalidArgumentException $exception) {
                        io()->error($exception->getMessage());

                        return;
                    }

                    try {
                        push_import_var_to_remote($projectSlug, $dryRun, $delete);
                    } catch (\Throwable $exception) {
                        io()->error($exception->getMessage());
                    }
                });
            },
        ];
    }
}
