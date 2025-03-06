<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\CakePHP\Integration;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;

/** @psalm-suppress UnusedClass */
class CommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    public function test_command_tracing(): void
    {
        if (PHP_VERSION_ID < 80100) {
            $this->markTestSkipped();
        }
        $this->assertCount(0, $this->storage);

        $this->exec('dummy');

        $this->assertExitSuccess();
        $this->assertCount(1, $this->storage);
        $this->assertSame('Command cake dummy', $this->storage[0]->getName());
    }
}
