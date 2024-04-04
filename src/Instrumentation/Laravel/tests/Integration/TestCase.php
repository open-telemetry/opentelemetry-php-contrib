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
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\InstrumentationHelper;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected ScopeInterface $scope;
    /** @var ArrayObject|ImmutableSpan[] $storage */
    protected ArrayObject $storage;
    protected TracerProvider $tracerProvider;

    public function setUp(): void
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

        // @todo Find a nicer way.
        InstrumentationHelper::instance()->setTracerProvider($this->tracerProvider);

        // This will create a fresh Laravel application, which is hooked by the above instrumentation activation.
        parent::setUp();
    }

    public function tearDown(): void
    {
        parent::tearDown();

        $this->scope->detach();
    }
}
