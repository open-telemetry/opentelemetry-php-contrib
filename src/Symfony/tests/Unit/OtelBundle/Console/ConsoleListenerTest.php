<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Symfony\Test\Unit\OtelBundle\Console;

use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Symfony\OtelBundle\Console\ConsoleListener;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class ConsoleListenerTest extends TestCase
{
    public function testListenerCreatesScopeForCommand(): void
    {
        $listener = new ConsoleListener(new NoopTracerProvider());
        $command = new Command('test');

        $scope = Context::storage()->scope();

        $listener->startCommand(new ConsoleCommandEvent($command, new ArrayInput([]), new NullOutput()));
        $this->assertNotSame($scope, Context::storage()->scope());

        $listener->terminateCommand(new ConsoleTerminateEvent($command, new ArrayInput([]), new NullOutput(), 0));
        $this->assertSame($scope, Context::storage()->scope());
    }

    public function testNameUsesCommandName(): void
    {
        $exporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider([new SimpleSpanProcessor($exporter)]);

        $listener = new ConsoleListener($tracerProvider);
        $command = new Command('test-command');

        $this->callListener($listener, $command);

        $this->assertSame('test-command', $exporter->getSpans()[0]->getName());
    }

    public function testNonZeroExitCodeSetsSpanStatusError(): void
    {
        $exporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider([new SimpleSpanProcessor($exporter)]);

        $listener = new ConsoleListener($tracerProvider);
        $command = new Command('test-command');

        $this->callListener($listener, $command, 1);

        $this->assertSame(StatusCode::STATUS_ERROR, $exporter->getSpans()[0]->getStatus()->getCode());
    }

    private function callListener(ConsoleListener $listener, Command $command, int $exitCode = 0): void
    {
        $listener->startCommand(new ConsoleCommandEvent($command, new ArrayInput([]), new NullOutput()));
        $listener->terminateCommand(new ConsoleTerminateEvent($command, new ArrayInput([]), new NullOutput(), $exitCode));
    }
}
