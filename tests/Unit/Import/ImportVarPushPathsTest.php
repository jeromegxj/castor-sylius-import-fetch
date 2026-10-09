<?php

declare(strict_types=1);

namespace Unit\Import;

use PHPUnit\Framework\TestCase;

use function SyliusStarter\ImportFetch\import_var_remote_dir;
use function SyliusStarter\ImportFetch\resolve_import_var_push_config;

final class ImportVarPushPathsTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $previousEnv = [];

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/src/var_push.php';

        foreach ([
            'IMPORT_VAR_PUSH_SSH_HOST',
            'IMPORT_VAR_PUSH_SSH_USER',
            'IMPORT_VAR_PUSH_REMOTE_DIR',
            'IMPORT_VAR_PUSH_SSH_PORT',
            'IMPORT_VAR_PUSH_IDENTITY_FILE',
        ] as $name) {
            $this->previousEnv[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $name => $value) {
            if (false === $value) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
    }

    public function testRemoteDirAppendsCastorImportVarSlug(): void
    {
        static::assertSame(
            '/home/debian/app/.castor/import/var/cocorico',
            import_var_remote_dir('cocorico', '/home/debian/app'),
        );
        static::assertSame(
            '/home/debian/app/.castor/import/var/cocorico',
            import_var_remote_dir('cocorico', '/home/debian/app/'),
        );
    }

    public function testResolveConfigRequiresHost(): void
    {
        putenv('IMPORT_VAR_PUSH_SSH_HOST');
        putenv('IMPORT_VAR_PUSH_REMOTE_DIR=/remote/project');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('IMPORT_VAR_PUSH_SSH_HOST');

        resolve_import_var_push_config();
    }

    public function testResolveConfigRequiresRemoteDir(): void
    {
        putenv('IMPORT_VAR_PUSH_SSH_HOST=example.test');
        putenv('IMPORT_VAR_PUSH_REMOTE_DIR');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('IMPORT_VAR_PUSH_REMOTE_DIR');

        resolve_import_var_push_config();
    }

    public function testResolveConfigDefaultsUserAndPort(): void
    {
        putenv('IMPORT_VAR_PUSH_SSH_HOST=example.test');
        putenv('IMPORT_VAR_PUSH_REMOTE_DIR=/remote/project');
        putenv('IMPORT_VAR_PUSH_SSH_USER');
        putenv('IMPORT_VAR_PUSH_SSH_PORT');

        $config = resolve_import_var_push_config();

        static::assertSame('example.test', $config['host']);
        static::assertSame('debian', $config['user']);
        static::assertSame(22, $config['port']);
        static::assertSame('/remote/project', $config['remoteDir']);
    }
}
