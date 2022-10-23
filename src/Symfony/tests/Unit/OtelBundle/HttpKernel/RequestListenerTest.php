<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\Test\Unit\OtelBundle\HttpKernel;

use Exception;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\Symfony\OtelBundle\HttpKernel\RequestListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

/**
 * @covers \OpenTelemetry\Symfony\OtelBundle\HttpKernel\RequestListener
 */
final class RequestListenerTest extends TestCase
{
    public function testListenerCreatesScopeForRequest(): void
    {
        $listener = new RequestListener(new NoopTracerProvider(), new NoopTextMapPropagator());
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();

        $scope = Context::storage()->scope();

        $listener->startRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->assertNotSame($scope, Context::storage()->scope());

        $listener->endScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->assertSame($scope, Context::storage()->scope());
    }

    public function testPropagatorIsCalledForMainRequest(): void
    {
        $propagator = $this->createMock(TextMapPropagatorInterface::class);
        $propagator->expects($this->once())->method('extract')->willReturnArgument(2);
        $listener = new RequestListener(
            new NoopTracerProvider(),
            $propagator,
        );
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();

        $listener->startRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->endScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
    }

    public function testPropagatorIsNotCalledForSubRequest(): void
    {
        $propagator = $this->createMock(TextMapPropagatorInterface::class);
        $propagator->expects($this->never())->method('extract');
        $listener = new RequestListener(
            new NoopTracerProvider(),
            $propagator,
        );
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();

        $listener->startRequest(new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST));
        $listener->endScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST));
    }

    /**
     * @dataProvider httpMethodProvider
     */
    public function testNameUsesHttpMethod(string $method): void
    {
        $exporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider([new SimpleSpanProcessor($exporter)]);

        $listener = new RequestListener($tracerProvider, new NoopTextMapPropagator());
        $request = new Request();
        $request->setMethod($method);

        $this->callListener($listener, $request);

        $this->assertSame("HTTP $method", $exporter->getSpans()[0]->getName());
    }

    public function httpMethodProvider(): iterable
    {
        yield ['GET'];
        yield ['POST'];
    }

    public function testRouteNameIsRecorded(): void
    {
        $exporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider([new SimpleSpanProcessor($exporter)]);

        $listener = new RequestListener($tracerProvider, new NoopTextMapPropagator());
        $request = new Request();
        $request->attributes->set('_route', 'route-name');

        $this->callListener($listener, $request);

        $this->assertSame('route-name', $exporter->getSpans()[0]->getName());
        $this->assertSame('route-name', $exporter->getSpans()[0]->getAttributes()->get('http.route'));
    }

    public function testStatusCode5xxSetsStatusError(): void
    {
        $exporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider([new SimpleSpanProcessor($exporter)]);

        $listener = new RequestListener($tracerProvider, new NoopTextMapPropagator());
        $request = new Request();
        $response = new Response();
        $response->setStatusCode(503);

        $this->callListener($listener, $request, $response);

        $this->assertSame(StatusCode::STATUS_ERROR, $exporter->getSpans()[0]->getStatus()->getCode());
    }

    public function testRequestHeaderAttributeIsSet(): void
    {
        $exporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider([new SimpleSpanProcessor($exporter)]);

        $listener = new RequestListener($tracerProvider, new NoopTextMapPropagator(), ['x-test']);
        $request = new Request();
        $request->headers->set('x-test', 'value');
        $request->headers->set('x-test2', 'value2');

        $this->callListener($listener, $request);

        $this->assertSame(['value'], $exporter->getSpans()[0]->getAttributes()->get('http.request.header.x_test'));
    }

    public function testResponseHeaderAttributeIsSet(): void
    {
        $exporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider([new SimpleSpanProcessor($exporter)]);

        $listener = new RequestListener($tracerProvider, new NoopTextMapPropagator(), [], ['x-test']);
        $request = new Request();
        $response = new Response();
        $response->headers->set('x-test', 'value');
        $response->headers->set('x-test2', 'value2');

        $this->callListener($listener, $request, $response);

        $this->assertSame(['value'], $exporter->getSpans()[0]->getAttributes()->get('http.response.header.x_test'));
    }

    public function testExceptionIsRecorded(): void
    {
        $exporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider([new SimpleSpanProcessor($exporter)]);

        $listener = new RequestListener($tracerProvider, new NoopTextMapPropagator(), [], ['x-test']);
        $request = new Request();
        $response = new Response();
        $response->headers->set('x-test', 'value');

        $this->callListenerException($listener, $request, new Exception('exception'));

        $this->assertSame('exception', $exporter->getSpans()[0]->getEvents()[0]->getName());
        $this->assertSame(StatusCode::STATUS_ERROR, $exporter->getSpans()[0]->getStatus()->getCode());
    }

    public function testRemoteContextIsExtracted(): void
    {
        $exporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider([new SimpleSpanProcessor($exporter)]);

        $listener = new RequestListener($tracerProvider, new TraceContextPropagator());
        $request = new Request();
        $request->headers->set('traceparent', '00-0af7651916cd43dd8448eb211c80319c-b9c7c989f97918e1-01');

        $this->callListener($listener, $request);

        $this->assertSame('0af7651916cd43dd8448eb211c80319c', $exporter->getSpans()[0]->getContext()->getTraceId());
    }

    private function callListener(RequestListener $listener, ?Request $request, ?Response $response = null): void
    {
        $request ??= new Request();
        $response ??= new Response();

        $kernel = $this->createMock(HttpKernelInterface::class);
        $listener->startRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->recordRoute(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->recordResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
        $listener->endScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->endRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->terminateRequest(new TerminateEvent($kernel, $request, $response));
    }

    private function callListenerException(RequestListener $listener, ?Request $request, Throwable $exception): void
    {
        $request ??= new Request();

        $kernel = $this->createMock(HttpKernelInterface::class);
        $listener->startRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->recordRoute(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->recordException(new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception));
        $listener->endScope(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->endRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
    }
}
