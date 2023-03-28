<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Laravel\tests\Integration;

use ArrayObject;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use OpenTelemetry\API\Common\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\Tests\Instrumentation\Laravel\TestCase;

class ByPassRouterKernel extends Kernel
{
    protected function sendRequestThroughRouter($request)
    {
        return new Response();
    }
}

class LaravelInstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    private ArrayObject $storage;
    private TracerProvider $tracerProvider;

    public function setUp(): void
    {
        parent::setUp();

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
        parent::tearDown();
    }

    public function test_the_application_returns_a_successful_response(): void
    {
        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/');
        $this->assertEquals(200, $response->status());
        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        $this->assertSame('HTTP GET', $span->getName());
    
        $response = Http::get('opentelemetry.io');
        $this->assertEquals(200, $response->status());
        $span = $this->storage->offsetGet(1);
        $this->assertSame('http GET https://opentelemetry.io/', $span->getName());
    }
    public function test_cache(): void
    {
        $this->assertCount(0, $this->storage);
        $response = $this->call('GET', '/hello');
        $this->assertEquals(200, $response->status());
        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        $this->assertSame('HTTP GET', $span->getName());
        $this->assertSame('http://localhost/hello', $span->getAttributes()->get(TraceAttributes::HTTP_URL));
        $this->assertCount(2, $span->getEvents());
    }
}
