<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\Console;

use Illuminate\Console\Command;
use Illuminate\Foundation\Console\Kernel;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Console\FailingCommand;
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

        // The parent command always finishes last.
        $this->assertSame('Command optimize:clear', $this->storage[6]->getName());

        // Sub-command execution order differs across Laravel versions, so check presence only.
        $names = array_map(fn ($span) => $span->getName(), iterator_to_array($this->storage));
        $this->assertContains('Command event:clear', $names);
        $this->assertContains('Command view:clear', $names);
        $this->assertContains('Command cache:clear', $names);
        $this->assertContains('Command route:clear', $names);
        $this->assertContains('Command config:clear', $names);
        $this->assertContains('Command clear-compiled', $names);
    }

    public function test_failing_command_sets_status_error(): void
    {
        $kernel = $this->kernel();
        $kernel->registerCommand(new FailingCommand());

        $exitCode = $kernel->handle(
            new \Symfony\Component\Console\Input\ArrayInput(['test:failing-command']),
            new \Symfony\Component\Console\Output\NullOutput(),
        );

        $this->assertEquals(Command::FAILURE, $exitCode);

        $this->assertCount(1, $this->storage);
        $this->assertSame('Command test:failing-command', $this->storage[0]->getName());
        $this->assertSame(StatusCode::STATUS_ERROR, $this->storage[0]->getStatus()->getCode());
    }

    private function kernel(): Kernel
    {
        /** @psalm-suppress PossiblyNullReference */
        return $this->app->make(Kernel::class);
    }
}
