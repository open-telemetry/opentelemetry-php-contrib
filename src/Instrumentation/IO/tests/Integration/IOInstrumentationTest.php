<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\IO\tests\Integration;

use ArrayObject;
use OpenTelemetry\API\Common\Instrumentation\Configurator;
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
    }

    public function test_io_calls(): void
    {
        $resource = fopen('php://memory', 'r');
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
        
        $str = file_get_contents('php://memory');
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

        $ch = curl_init('foo');
        $this->assertCount(6, $this->storage);
        $this->span = $this->storage->offsetGet(5);
        $this->assertSame('curl_init', $this->span->getName());
        $this->assertSame('foo', $this->span->getAttributes()->get('code.params.uri'));

        curl_exec($ch);
        $this->assertCount(7, $this->storage);
        $this->span = $this->storage->offsetGet(6);
        $this->assertSame('curl_exec', $this->span->getName());
    }
}
