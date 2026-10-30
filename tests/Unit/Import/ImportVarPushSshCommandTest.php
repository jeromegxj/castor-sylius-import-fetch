<?php

declare(strict_types=1);

namespace SyliusStarter\ImportFetch\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;

use function SyliusStarter\ImportFetch\build_import_var_push_ssh_command;
use function SyliusStarter\ImportFetch\expand_import_var_push_path;

final class ImportVarPushSshCommandTest extends TestCase
{
    private string|false $previousHome = false;

    protected function setUp(): void
    {
        $this->previousHome = getenv('HOME');
    }

    protected function tearDown(): void
    {
        if (false === $this->previousHome) {
            putenv('HOME');
        } else {
            putenv('HOME=' . $this->previousHome);
        }
    }

    public function testSshCommandWithoutIdentityFile(): void
    {
        static::assertSame(
            "ssh '-p' '2222' '-o' 'BatchMode=yes'",
            build_import_var_push_ssh_command($this->config(2222, null)),
        );
    }

    public function testSshCommandEscapesIdentityFile(): void
    {
        static::assertSame(
            "ssh '-p' '22' '-o' 'BatchMode=yes' '-i' '/home/me/my keys/id_it'\\''s'",
            build_import_var_push_ssh_command($this->config(22, "/home/me/my keys/id_it's")),
        );
        static::assertSame(
            "ssh '-p' '22' '-o' 'BatchMode=yes'",
            build_import_var_push_ssh_command($this->config(22, '')),
        );
    }

    public function testExpandsHomeInPaths(): void
    {
        putenv('HOME=/home/me');

        static::assertSame('/home/me/.ssh/id_ed25519', expand_import_var_push_path('~/.ssh/id_ed25519'));
        static::assertSame('/etc/ssh/key', expand_import_var_push_path('/etc/ssh/key'));
        static::assertSame('~other/key', expand_import_var_push_path('~other/key'));
    }

    /**
     * @return array{host: string, user: string, port: int, identityFile: ?string, remoteDir: string}
     */
    private function config(int $port, ?string $identityFile): array
    {
        return [
            'host' => 'example.test',
            'user' => 'debian',
            'port' => $port,
            'identityFile' => $identityFile,
            'remoteDir' => '/remote/project',
        ];
    }
}
