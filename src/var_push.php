<?php

declare(strict_types=1);

namespace SyliusStarter\ImportFetch;

use SyliusStarter\Import\ImportContext;

use function Castor\io;
use function Castor\run;
use function SyliusStarter\Import\castor_host_dir;
use function SyliusStarter\Import\import_log as import_package_log;
use function SyliusStarter\Import\load_castor_env;
use function SyliusStarter\Import\resolve_cli_project_slug;

/**
 * @return array{host: string, user: string, port: int, identityFile: ?string, remoteDir: string}
 */
function resolve_import_var_push_config(): array
{
    ensure_import_var_push_env_loaded();

    $host = import_var_push_env('IMPORT_VAR_PUSH_SSH_HOST');
    $remoteDir = import_var_push_env('IMPORT_VAR_PUSH_REMOTE_DIR');

    if (null === $host || '' === $host) {
        throw new \RuntimeException(
            'IMPORT_VAR_PUSH_SSH_HOST is not set. Add it to .env.local at the project root.',
        );
    }

    if (null === $remoteDir || '' === $remoteDir) {
        throw new \RuntimeException(
            'IMPORT_VAR_PUSH_REMOTE_DIR is not set (remote project root, e.g. /home/debian/sylius-starter-sales-kit).',
        );
    }

    $user = import_var_push_env('IMPORT_VAR_PUSH_SSH_USER') ?? 'debian';
    $port = (int) (import_var_push_env('IMPORT_VAR_PUSH_SSH_PORT') ?? '22');
    $identityRaw = import_var_push_env('IMPORT_VAR_PUSH_IDENTITY_FILE');
    $identityFile = null;

    if (null !== $identityRaw && '' !== trim($identityRaw)) {
        $identityFile = expand_import_var_push_path(trim($identityRaw));
    }

    if ($port < 1 || $port > 65535) {
        throw new \RuntimeException(\sprintf('Invalid IMPORT_VAR_PUSH_SSH_PORT "%d".', $port));
    }

    return [
        'host' => $host,
        'user' => $user,
        'port' => $port,
        'identityFile' => $identityFile,
        'remoteDir' => rtrim($remoteDir, '/\\'),
    ];
}

function import_var_local_dir(string $projectSlug): string
{
    return castor_host_dir($projectSlug);
}

function import_var_remote_dir(string $projectSlug, string $remoteProjectRoot): string
{
    return rtrim($remoteProjectRoot, '/\\') . '/.castor/import/var/' . $projectSlug;
}

function push_import_var_to_remote(string $projectSlug, bool $dryRun = false, bool $delete = false): void
{
    ensure_import_var_push_env_loaded();

    $localDir = import_var_local_dir($projectSlug);

    if (!is_dir($localDir)) {
        throw new \RuntimeException(\sprintf(
            'Local import directory not found: %s. Run sylius:import:existing:fetch or sylius:import:ai:build first.',
            $localDir,
        ));
    }

    if (!is_file($localDir . '/products.yaml') && !is_file($localDir . '/project.yaml')) {
        throw new \RuntimeException(\sprintf(
            'Nothing to push in %s (missing products.yaml and project.yaml).',
            $localDir,
        ));
    }

    $config = resolve_import_var_push_config();
    $remoteDir = import_var_remote_dir($projectSlug, $config['remoteDir']);
    $destination = \sprintf('%s@%s:%s/', $config['user'], $config['host'], $remoteDir);

    import_package_log(\sprintf('Pushing import var: %s/ → %s', $localDir, $destination));

    $sshCommand = build_import_var_push_ssh_command($config);
    $mkdirCommand = array_merge(
        ['ssh'],
        import_var_push_ssh_args($config),
        [\sprintf('%s@%s', $config['user'], $config['host']), 'mkdir', '-p', $remoteDir],
    );

    if (!$dryRun) {
        run($mkdirCommand);
    } else {
        import_package_log('Dry run: would run: ' . implode(' ', array_map('escapeshellarg', $mkdirCommand)));
    }

    $rsyncFlags = ['-avz'];

    if ($dryRun) {
        $rsyncFlags[] = '-n';
    }

    if ($delete) {
        $rsyncFlags[] = '--delete';
    }

    $rsyncCommand = [
        'rsync',
        ...$rsyncFlags,
        '-e',
        $sshCommand,
        $localDir . '/',
        $destination,
    ];

    run($rsyncCommand);

    if ($dryRun) {
        io()->comment('Dry run completed (no files transferred).');
    } else {
        io()->success(\sprintf('Import var pushed for "%s" to %s.', $projectSlug, $remoteDir));
    }
}

function ensure_import_var_push_env_loaded(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    if (null !== ImportContext::tryCurrent()) {
        load_castor_env();
    }

    $loaded = true;
}

function import_var_push_env(string $name): ?string
{
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

    if (!\is_string($value)) {
        return null;
    }

    $value = trim($value);

    return '' === $value ? null : $value;
}

function expand_import_var_push_path(string $path): string
{
    if (str_starts_with($path, '~/')) {
        $home = getenv('HOME');

        if (\is_string($home) && '' !== $home) {
            return $home . substr($path, 1);
        }
    }

    return $path;
}

/**
 * @param array{host: string, user: string, port: int, identityFile: ?string, remoteDir: string} $config
 *
 * @return list<string>
 */
function import_var_push_ssh_args(array $config): array
{
    $args = [
        '-p',
        (string) $config['port'],
        '-o',
        'BatchMode=yes',
    ];

    if (null !== $config['identityFile'] && '' !== $config['identityFile']) {
        $args[] = '-i';
        $args[] = $config['identityFile'];
    }

    return $args;
}

/**
 * @param array{host: string, user: string, port: int, identityFile: ?string, remoteDir: string} $config
 */
function build_import_var_push_ssh_command(array $config): string
{
    $parts = ['ssh'];

    foreach (import_var_push_ssh_args($config) as $arg) {
        $parts[] = escapeshellarg($arg);
    }

    return implode(' ', $parts);
}
