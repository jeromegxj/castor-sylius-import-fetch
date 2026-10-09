<?php

declare(strict_types=1);

namespace Unit\Tasks;

use Castor\Attribute\AsTask;
use SyliusStarter\ImportFetch\Tasks\ExistingFetchTasks;
use PHPUnit\Framework\TestCase;

final class ExistingFetchTasksTest extends TestCase
{
    public function testRegistersFetchAndVarPushTasks(): void
    {
        $tasks = new ExistingFetchTasks('app', '/tmp/app');
        $names = [];

        foreach ($tasks() as $task) {
            /** @var AsTask $descriptor */
            $descriptor = $task['task'];
            $names[] = $descriptor->namespace . ':' . $descriptor->name;
            static::assertArrayHasKey('function', $task);
        }

        static::assertContains('sylius:import:existing:fetch', $names);
        static::assertContains('sylius:import:var:push', $names);
    }
}
