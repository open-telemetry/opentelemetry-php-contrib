<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Contrib\Instrumentation\Symfony\ConsoleInstrumentation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class ConsoleInstrumentationTest extends AbstractTest
{
    public function test_command_run_creates_root_span(): void
    {
        $command = new class() extends Command {
            protected static $defaultName = 'app:test-command';

            protected function configure(): void
            {
                $this->setName('app:test-command');
            }

            protected function execute($input, $output): int
            {
                return Command::SUCCESS;
            }
        };

        $this->assertCount(0, $this->storage);

        $exitCode = $command->run(new ArrayInput([]), new NullOutput());

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertCount(1, $this->storage);

        $span = $this->storage[0];
        $this->assertSame('app:test-command', $span->getName());
        $this->assertSame(SpanKind::KIND_INTERNAL, $span->getKind());
        $this->assertSame(Command::SUCCESS, $span->getAttributes()->get(ConsoleInstrumentation::ATTRIBUTE_CONSOLE_EXIT_CODE));
        $this->assertNotSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
    }

    public function test_command_run_with_non_zero_exit_code_is_error(): void
    {
        $command = new class() extends Command {
            protected static $defaultName = 'app:failing-command';

            protected function configure(): void
            {
                $this->setName('app:failing-command');
            }

            protected function execute($input, $output): int
            {
                return Command::FAILURE;
            }
        };

        $exitCode = $command->run(new ArrayInput([]), new NullOutput());

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertCount(1, $this->storage);

        $span = $this->storage[0];
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(Command::FAILURE, $span->getAttributes()->get(ConsoleInstrumentation::ATTRIBUTE_CONSOLE_EXIT_CODE));
    }

    public function test_command_run_records_exception(): void
    {
        $command = new class() extends Command {
            protected static $defaultName = 'app:throwing-command';

            protected function configure(): void
            {
                $this->setName('app:throwing-command');
            }

            protected function execute($input, $output): int
            {
                throw new \RuntimeException('something went wrong');
            }
        };

        try {
            $command->run(new ArrayInput([]), new NullOutput());
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('something went wrong', $e->getMessage());
        }

        $this->assertCount(1, $this->storage);
        $span = $this->storage[0];
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('something went wrong', $span->getStatus()->getDescription());
    }
}
