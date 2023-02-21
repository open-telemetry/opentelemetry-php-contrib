<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Laravel\tests\Integration;

use ArrayObject;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use OpenTelemetry\API\Common\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

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
    public function test_http_kernel_handle(): void
    {
        $app = new Application('.');

        $container = new Container();
        $events_dispatcher = new Dispatcher();
        $router = new Router($events_dispatcher, $container);
        $kernel = new ByPassRouterKernel($app, $router);
        $request = Request::create('/', 'GET');
        $this->assertCount(0, $this->storage);
        $kernel->handle($request);
        $this->assertCount(1, $this->storage);
    }
}
