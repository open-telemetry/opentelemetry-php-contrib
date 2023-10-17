<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\CodeIgniter\tests\Integration;

use ArrayObject;
use CodeIgniter\Test\CIUnitTestCase;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

abstract class AbstractTest extends CIUnitTestCase
{
    private ScopeInterface $scope;
    protected ArrayObject $storage;

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
            ->withPropagator(TraceContextPropagator::getInstance())
            ->activate();
        
        parent::setUp();
    }

    public function tearDown(): void
    {
        parent::tearDown();

        $this->scope->detach();
    }
}
