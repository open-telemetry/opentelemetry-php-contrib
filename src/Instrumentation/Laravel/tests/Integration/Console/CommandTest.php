<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Console\OptimizeClearCommand;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\TestCase;

/** @psalm-suppress UnusedClass */
class CommandTest extends TestCase
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

        $i = 0;
        foreach ((new OptimizeClearCommand())->getOptimizeClearTasks() as $task) {
            $this->assertSame(sprintf('Command %s', $task), $this->storage[$i++]->getName());
        }
        $this->assertSame('Command optimize:clear', $this->storage[$i]->getName());
    }

    private function kernel(): Kernel
    {
        /** @psalm-suppress PossiblyNullReference */
        return $this->app->make(Kernel::class);
    }
}
