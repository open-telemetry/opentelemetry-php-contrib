<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration;

use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as LogInMemoryExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as SpanInMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected ScopeInterface $scope;
    /** @var ArrayObject|ImmutableSpan[] $storage */
    protected ArrayObject $storage;
    protected ArrayObject $loggerStorage;
    protected TracerProvider $tracerProvider;
    protected LoggerProvider $loggerProvider;

    public function setUp(): void
    {
        parent::setUp();

        $this->storage = new ArrayObject();
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new SpanInMemoryExporter($this->storage),
            ),
        );

        $this->loggerProvider = new LoggerProvider(
            new SimpleLogRecordProcessor(
                new LogInMemoryExporter($this->storage),
            ),
            new InstrumentationScopeFactory(Attributes::factory())
        );

        $this->scope = Configurator::create()
            ->withTracerProvider($this->tracerProvider)
            ->withLoggerProvider($this->loggerProvider)
            ->activate();
    }

    public function tearDown(): void
    {
        parent::tearDown();

        $this->scope->detach();
    }
}
