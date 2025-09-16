<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\IO\tests\Integration;

use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

class IOInstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    private ArrayObject $storage;
    private ImmutableSpan $span;

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
            ->activate();
    }

    public function tearDown(): void
    {
        $this->scope->detach();
        // Clean up any output buffers that might have been started during tests
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    public function test_io_calls(): void
    {
        $resource = fopen('php://memory', 'r');
        $this->assertIsResource($resource);
        $this->assertCount(1, $this->storage);
        $this->span = $this->storage->offsetGet(0);
        $this->assertSame('fopen', $this->span->getName());
        $this->assertSame('php://memory', $this->span->getAttributes()->get('code.params.filename'));
        $this->assertSame('r', $this->span->getAttributes()->get('code.params.mode'));

        file_put_contents('php://memory', 'data');
        $this->assertCount(2, $this->storage);
        $this->span = $this->storage->offsetGet(1);
        $this->assertSame('file_put_contents', $this->span->getName());
        $this->assertSame('php://memory', $this->span->getAttributes()->get('code.params.filename'));

        file_get_contents('php://memory');
        $this->assertCount(3, $this->storage);
        $this->span = $this->storage->offsetGet(2);
        $this->assertSame('file_get_contents', $this->span->getName());
        $this->assertSame('php://memory', $this->span->getAttributes()->get('code.params.filename'));

        fwrite($resource, 'data');
        $this->assertCount(4, $this->storage);
        $this->span = $this->storage->offsetGet(3);
        $this->assertSame('fwrite', $this->span->getName());

        fread($resource, 1);
        $this->assertCount(5, $this->storage);
        $this->span = $this->storage->offsetGet(4);
        $this->assertSame('fread', $this->span->getName());

    }

    public function test_output_buffer_calls(): void
    {
        // Make sure we start with a clean state
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        // Test ob_start with parameters
        $callback = function ($buffer) {
            return $buffer;
        };
        ob_start($callback, 4096, PHP_OUTPUT_HANDLER_STDFLAGS);
        $this->assertCount(1, $this->storage);
        $this->span = $this->storage->offsetGet(0);
        $this->assertSame('ob_start', $this->span->getName());
        $this->assertTrue($this->span->getAttributes()->get('code.params.has_callback'));
        $this->assertSame(4096, $this->span->getAttributes()->get('code.params.chunk_size'));
        $this->assertSame(PHP_OUTPUT_HANDLER_STDFLAGS, $this->span->getAttributes()->get('code.params.flags'));
        
        // Test ob_clean
        ob_clean();
        $this->assertCount(2, $this->storage);
        $this->span = $this->storage->offsetGet(1);
        $this->assertSame('ob_clean', $this->span->getName());
        
        // Test ob_flush
        ob_flush();
        $this->assertCount(3, $this->storage);
        $this->span = $this->storage->offsetGet(2);
        $this->assertSame('ob_flush', $this->span->getName());
        
        // Test flush
        flush();
        $this->assertCount(4, $this->storage);
        $this->span = $this->storage->offsetGet(3);
        $this->assertSame('flush', $this->span->getName());
        
        // Clean up
        ob_end_clean();
    }
}
