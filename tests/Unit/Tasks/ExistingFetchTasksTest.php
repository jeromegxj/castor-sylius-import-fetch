<?php

declare(strict_types=1);

namespace Unit\Tasks;

use Castor\Attribute\AsTask;
use Castor\Sylius\ImportFetch\Tasks\ExistingFetchTasks;
use PHPUnit\Framework\TestCase;

final class ExistingFetchTasksTest extends TestCase
{
    public function testRegistersExistingFetchTask(): void
    {
        $tasks = new ExistingFetchTasks('app', '/tmp/app');

        foreach ($tasks() as $task) {
            /** @var AsTask $descriptor */
            $descriptor = $task['task'];
            static::assertSame('fetch', $descriptor->name);
            static::assertSame('sylius:import:existing', $descriptor->namespace);
            static::assertArrayHasKey('function', $task);

            return;
        }

        static::fail('Expected sylius:import:existing:fetch task to be registered.');
    }
}
