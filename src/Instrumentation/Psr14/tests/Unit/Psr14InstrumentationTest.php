<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\Psr14\Tests\Unit;

use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Contrib\Instrumentation\Psr14\Psr14Instrumentation;
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
    private EventDispatcherInterface $dispatcher;

    public function setUp(): void
    {
        $this->storage = new ArrayObject();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage)
            )
        );
        $this->scope = Configurator::create()
            ->withTracerProvider($tracerProvider)
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

    public function test_name_constant(): void
    {
        $this->assertSame('psr14', Psr14Instrumentation::NAME);
    }

    public function test_dispatch_creates_span_with_event_name(): void
    {
        $event = new SampleEventClass();
        $this->dispatcher->dispatch($event);

        $this->assertGreaterThanOrEqual(1, count($this->storage));

        $span = $this->findSpan(sprintf('event %s', SampleEventClass::class));
        $this->assertNotNull($span);
        $this->assertSame(SampleEventClass::class, $span->getAttributes()->get('psr14.event.name'));
    }

    public function test_dispatch_sets_code_attributes(): void
    {
        $this->dispatcher->dispatch(new SampleEventClass());

        $span = $this->findSpan(sprintf('event %s', SampleEventClass::class));
        $this->assertNotNull($span);
        $this->assertNotNull($span->getAttributes()->get('code.function.name'));
        $this->assertStringContainsString('dispatch', $span->getAttributes()->get('code.function.name'));
    }

    public function test_dispatch_multiple_events(): void
    {
        $initialCount = count($this->storage);
        $this->dispatcher->dispatch(new SampleEventClass('first'));
        $this->dispatcher->dispatch(new SampleEventClass('second'));

        $this->assertGreaterThanOrEqual($initialCount + 2, count($this->storage));
    }

    public function test_dispatch_with_exception_records_error(): void
    {
        $dispatcher = new class() implements EventDispatcherInterface {
            public function dispatch(object $event)
            {
                throw new \RuntimeException('dispatch failed');
            }
        };

        try {
            $dispatcher->dispatch(new SampleEventClass());
        } catch (\RuntimeException $e) {
            $this->assertSame('dispatch failed', $e->getMessage());
        }

        $span = $this->findSpan(sprintf('event %s', SampleEventClass::class));
        $this->assertNotNull($span);
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('dispatch failed', $span->getStatus()->getDescription());
        $this->assertNotEmpty($span->getEvents());
        $this->assertSame('exception', $span->getEvents()[0]->getName());
    }

    /**
     * Find the first span with the given name.
     */
    private function findSpan(string $name): ?ImmutableSpan
    {
        foreach ($this->storage as $span) {
            if ($span->getName() === $name) {
                return $span;
            }
        }

        return null;
    }
}
