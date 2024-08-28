<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\CakePHP\Integration;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;

class CommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    public function test_command_tracing(): void
    {
        $this->assertCount(0, $this->storage);

        $this->exec('dummy');

        $this->assertExitSuccess();
        $this->assertCount(1, $this->storage);
        $this->assertSame('Command cake dummy', $this->storage[0]->getName());
    }
}
