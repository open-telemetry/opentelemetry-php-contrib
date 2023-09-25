<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration;

use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

trait WithInstrumentation
{
    protected ScopeInterface $scope;
    /** @var ArrayObject|ImmutableSpan[] $storage */
    protected ArrayObject $storage;
    protected TracerProvider $tracerProvider;

    public function setUpWithInstrumentation(): void
    {
        $this->storage = new ArrayObject();
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage),
            ),
        );

        $this->scope = Configurator::create()
            ->withTracerProvider($this->tracerProvider)
            ->activate();
    }

    public function tearDownWithInstrumentation(): void
    {
        $this->scope->detach();
    }
}
