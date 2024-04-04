<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;

class ConsoleInstrumentationTest extends TestCase
{
    public function test_command_tracing(): void
    {
        $this->assertCount(0, $this->storage);

        $exitCode = $this->kernel()->handle(
            new \Symfony\Component\Console\Input\ArrayInput(['optimize:clear']),
            new \Symfony\Component\Console\Output\NullOutput(),
        );

        $this->assertEquals(Command::SUCCESS, $exitCode);

        /**
         * The storage appends spans as they are marked as ended. eg: `$span->end()`.
         * So in this test, `optimize:clear` calls additional commands which complete first
         * and thus appear in the stack ahead of it.
         *
         * @see \Illuminate\Foundation\Console\OptimizeClearCommand::handle() for the additional commands/spans.
         */
        $this->assertCount(7, $this->storage);

        $this->assertSame('Command event:clear', $this->storage[0]->getName());
        $this->assertSame('Command view:clear', $this->storage[1]->getName());
        $this->assertSame('Command cache:clear', $this->storage[2]->getName());
        $this->assertSame('Command route:clear', $this->storage[3]->getName());
        $this->assertSame('Command config:clear', $this->storage[4]->getName());
        $this->assertSame('Command clear-compiled', $this->storage[5]->getName());
        $this->assertSame('Command optimize:clear', $this->storage[6]->getName());
    }

    private function kernel(): Kernel
    {
        /** @psalm-suppress PossiblyNullReference */
        return $this->app->make(Kernel::class);
    }
}
