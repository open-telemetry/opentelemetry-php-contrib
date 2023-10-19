<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration;

use OpenTelemetry\Contrib\Propagation\TraceResponse\TraceResponsePropagator;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Controller\ArgumentResolverInterface;
use Symfony\Component\HttpKernel\Controller\ControllerResolverInterface;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class SymfonyInstrumentationTest extends AbstractTest
{
    public function test_http_kernel_handle_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        $kernel = $this->getHttpKernel(new EventDispatcher(), function () {
            throw new \RuntimeException();
        });
        $this->assertCount(0, $this->storage);

        $response = $kernel->handle(new Request());

        $this->assertArrayHasKey(
            TraceResponsePropagator::TRACERESPONSE,
            $response->headers->all(),
            'traceresponse header is present if TraceResponsePropagator is present'
        );
    }

    public function test_http_kernel_handle_attributes(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher());
        $this->assertCount(0, $this->storage);
        $request = new Request();
        $request->attributes->set('_route', 'test_route');

        $response = $kernel->handle($request);

        $attributes = $this->storage[0]->getAttributes();
        $this->assertCount(1, $this->storage);
        $this->assertEquals('HTTP GET', $this->storage[0]->getName());
        $this->assertEquals('http://:/', $attributes->get(TraceAttributes::URL_FULL));
        $this->assertEquals('GET', $attributes->get(TraceAttributes::HTTP_REQUEST_METHOD));
        $this->assertEquals('http', $attributes->get(TraceAttributes::URL_SCHEME));
        $this->assertEquals('test_route', $attributes->get(TraceAttributes::HTTP_ROUTE));
        $this->assertEquals(200, $attributes->get(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertEquals('1.0', $attributes->get(TraceAttributes::NETWORK_PROTOCOL_VERSION));
        $this->assertEquals(5, $attributes->get(TraceAttributes::HTTP_RESPONSE_BODY_SIZE));

        $this->assertArrayHasKey(
            TraceResponsePropagator::TRACERESPONSE,
            $response->headers->all(),
            'traceresponse header is present if TraceResponsePropagator is present'
        );
    }

    public function test_http_kernel_handle_stream_response(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher(), fn () => new StreamedResponse(function () {
            echo 'Hello';
            flush();
        }));
        $this->assertCount(0, $this->storage);

        $response = $kernel->handle(new Request());
        $this->assertCount(1, $this->storage);
        $this->assertNull($this->storage[0]->getAttributes()->get(TraceAttributes::HTTP_RESPONSE_BODY_SIZE));

        $this->assertArrayHasKey(
            TraceResponsePropagator::TRACERESPONSE,
            $response->headers->all(),
            'traceresponse header is present if TraceResponsePropagator is present'
        );
    }

    public function test_http_kernel_handle_binary_file_response(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher(), fn () => new BinaryFileResponse(__FILE__));
        $this->assertCount(0, $this->storage);

        $response = $kernel->handle(new Request());
        $this->assertCount(1, $this->storage);
        $this->assertNull($this->storage[0]->getAttributes()->get(TraceAttributes::HTTP_RESPONSE_BODY_SIZE));

        $this->assertArrayHasKey(
            TraceResponsePropagator::TRACERESPONSE,
            $response->headers->all(),
            'traceresponse header is present if TraceResponsePropagator is present'
        );
    }

    public function test_http_kernel_handle_with_empty_route(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher());
        $this->assertCount(0, $this->storage);
        $request = new Request();
        $request->attributes->set('_route', '');

        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $this->assertCount(1, $this->storage);
        $this->assertFalse($this->storage[0]->getAttributes()->has(TraceAttributes::HTTP_ROUTE));

        $this->assertArrayHasKey(
            TraceResponsePropagator::TRACERESPONSE,
            $response->headers->all(),
            'traceresponse header is present if TraceResponsePropagator is present'
        );
    }

    public function test_http_kernel_handle_without_route(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher());
        $this->assertCount(0, $this->storage);

        $response = $kernel->handle(new Request(), HttpKernelInterface::MAIN_REQUEST, true);
        $this->assertCount(1, $this->storage);
        $this->assertFalse($this->storage[0]->getAttributes()->has(TraceAttributes::HTTP_ROUTE));

        $this->assertArrayHasKey(
            TraceResponsePropagator::TRACERESPONSE,
            $response->headers->all(),
            'traceresponse header is present if TraceResponsePropagator is present'
        );
    }

    private function getHttpKernel(EventDispatcherInterface $eventDispatcher, $controller = null, RequestStack $requestStack = null, array $arguments = []): HttpKernel
    {
        $controller ??= fn () => new Response('Hello');

        $controllerResolver = $this->createMock(ControllerResolverInterface::class);
        $controllerResolver
            ->method('getController')
            ->willReturn($controller);

        $argumentResolver = $this->createMock(ArgumentResolverInterface::class);
        $argumentResolver
            ->method('getArguments')
            ->willReturn($arguments);

        return new HttpKernel($eventDispatcher, $controllerResolver, $requestStack, $argumentResolver);
    }
}
