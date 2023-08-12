<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Laravel\Integration;

use ArrayObject;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\Tests\Instrumentation\Laravel\TestCase;

class CommandWatcherTest extends TestCase
{
    use WithConsoleEvents;

    private ScopeInterface $scope;
    private ArrayObject $storage;

    public function setUp(): void
    {
        parent::setUp();

        $this->storage = new ArrayObject();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage)
            )
        );

        $this->scope = Configurator::create()
            ->withTracerProvider($tracerProvider)
            ->activate();
    }

    public function tearDown(): void
    {
        $this->scope->detach();
        parent::tearDown();
    }

    public function test_command_tracing(): void
    {
        $this->assertCount(0, $this->storage);
        $exitCode = $this->withoutMockingConsoleOutput()->artisan('about');
        $this->assertEquals(Command::SUCCESS, $exitCode);
        $this->assertCount(1, $this->storage);

        /** @var ImmutableSpan $span */
        $span = $this->storage->offsetGet(0);
        $this->assertSame('Console about', $span->getName());
        $this->assertCount(1, $span->getEvents());
        $event = $span->getEvents()[0];
        $this->assertSame([
            'command' => 'about',
            'exit-code' => 0,
        ], $event->getAttributes()->toArray());
    }
}
