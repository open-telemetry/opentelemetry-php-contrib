<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\opcache\tests\Integration;

use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Contrib\Instrumentation\opcache\OpcacheInstrumentation;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class OpcacheInstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    private ArrayObject $storage;
    private TracerProvider $tracerProvider;

    public function setUp(): void
    {
        $this->storage = new ArrayObject();
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage)
            )
        );

        $this->scope = Configurator::create()
            ->withTracerProvider($this->tracerProvider)
            ->activate();
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }
    
    public function test_add_opcache_metrics_to_root_span(): void
    {
        
        // Create a root span
        $tracer = $this->tracerProvider->getTracer('test');
        $rootSpan = $tracer->spanBuilder('root_span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();
        
        // Activate the root span
        $scope = $rootSpan->activate();
        
        try {
            // Call the method to add opcache metrics to the root span
            opcacheInstrumentation::addOpcacheMetricsToRootSpan();
            
            // End the span
            $rootSpan->end();
            
            // Verify that the span has opcache attributes
            $this->assertCount(1, $this->storage);
            $span = $this->storage->offsetGet(0);
            
            // At minimum, it should have the opcache.enabled attribute
            $attributes = $span->getAttributes();
            $this->assertTrue($attributes->has('opcache.enabled'));
            
            // If OPcache is enabled and available, check for more attributes
            if (function_exists('opcache_get_status') && @opcache_get_status(false)) {
                $this->assertTrue($attributes->has('opcache.available'));
                $this->assertTrue($attributes->has('opcache.memory.used_bytes'));
                $this->assertTrue($attributes->has('opcache.memory.free_bytes'));
                $this->assertTrue($attributes->has('opcache.memory.wasted_bytes'));
                $this->assertTrue($attributes->has('opcache.scripts.cached'));
                $this->assertTrue($attributes->has('opcache.hits.total'));
                $this->assertTrue($attributes->has('opcache.misses.total'));
            }
        } finally {
            $scope->detach();
        }
    }
    
    public function test_capture_opcache_metrics(): void
    {
        
        // Create a span
        $tracer = $this->tracerProvider->getTracer('test');
        $span = $tracer->spanBuilder('test_span')->startSpan();
        
        // Call the captureOpcacheMetrics method using reflection
        $reflectionClass = new ReflectionClass(opcacheInstrumentation::class);
        $method = $reflectionClass->getMethod('captureOpcacheMetrics');
        //$method->setAccessible(true);
        $method->invoke(null, $span);
        
        // End the span
        $span->end();
        
        // Verify that the span has opcache attributes
        $this->assertCount(1, $this->storage);
        $storedSpan = $this->storage->offsetGet(0);
        $attributes = $storedSpan->getAttributes();
        
        // At minimum, it should have the opcache.enabled attribute
        $this->assertTrue($attributes->has('opcache.enabled'));
        
        // If OPcache is enabled and available, check for more attributes
        if (function_exists('opcache_get_status') && @opcache_get_status(false)) {
            $this->assertTrue($attributes->has('opcache.available'));
            $this->assertTrue($attributes->has('opcache.memory.used_bytes'));
            $this->assertTrue($attributes->has('opcache.memory.free_bytes'));
            $this->assertTrue($attributes->has('opcache.memory.wasted_bytes'));
            $this->assertTrue($attributes->has('opcache.scripts.cached'));
            $this->assertTrue($attributes->has('opcache.hits.total'));
            $this->assertTrue($attributes->has('opcache.misses.total'));
        }
    }

}
