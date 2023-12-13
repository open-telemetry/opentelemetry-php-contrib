<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\Psr14\Tests\Integration;

use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Instrumentation\Psr14\Tests\Fixture\SampleEventClass;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

class Psr14InstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    private ArrayObject $storage;
    private object $sampleEvent;
    private TracerProvider $tracerProvider;

    private EventDispatcherInterface $dispatcher;

    public function setUp(): void
    {
        $this->storage = new ArrayObject();
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage)
            )
        );
        $this->sampleEvent = new SampleEventClass();

        $this->scope = Configurator::create()
           ->withTracerProvider($this->tracerProvider)
           ->withPropagator(new TraceContextPropagator())
           ->activate();

        $this->dispatcher = new class() implements EventDispatcherInterface {
            public function dispatch(object $event)
            {
                return $event;
            }
        };
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    public function test_event_dispatch(): void
    {
        $this->assertCount(0, $this->storage);
        $this->dispatcher->dispatch($this->sampleEvent);
        $this->assertCount(1, $this->storage);

        /** @var ImmutableSpan $span */
        $span = $this->storage[0];

        $this->assertEquals(sprintf('event %s', SampleEventClass::class), $span->getName());
        $this->assertEquals(SampleEventClass::class, $span->getAttributes()->get('psr14.event.name'));
    }
}
