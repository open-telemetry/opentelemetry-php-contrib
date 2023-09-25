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
        $count = 8;
        $this->assertCount($count, $this->storage);

        $span = $this->storage[--$count];
        $this->assertSame('Artisan handler', $span->getName());

        $span = $this->storage[--$count];
        $this->assertSame('Command optimize:clear', $span->getName());
    }

    private function kernel(): Kernel
    {
        /** @psalm-suppress PossiblyNullReference */
        return $this->app->make(Kernel::class);
    }
}
