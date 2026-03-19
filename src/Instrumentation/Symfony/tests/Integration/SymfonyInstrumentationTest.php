<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
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
use Symfony\Component\HttpKernel\Exception\HttpException;
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
        $kernel->terminate(new Request(), $response);

        $this->assertArrayHasKey(
            TraceResponsePropagator::TRACERESPONSE,
            $response->headers->all(),
            'traceresponse header is present if TraceResponsePropagator is present'
        );
    }

    public function test_http_kernel_marks_root_as_erroneous(): void
    {
        $this->expectException(HttpException::class);
        $kernel = $this->getHttpKernel(new EventDispatcher(), function () {
            throw new HttpException(500, 'foo');
        });
        $this->assertCount(0, $this->storage);

        $response = $kernel->handle(new Request(), HttpKernelInterface::MAIN_REQUEST, true);
        $kernel->terminate(new Request(), $response);

        $this->assertCount(1, $this->storage);
        $this->assertSame(500, $this->storage[0]->getAttributes()->get(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));

        $this->assertSame(StatusCode::STATUS_ERROR, $this->storage[0]->getStatus()->getCode());
    }

    public function test_http_kernel_handle_attributes(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher());
        $this->assertCount(0, $this->storage);
        $request = new Request();
        $request->attributes->set('_route', 'test_route');

        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        $attributes = $this->storage[0]->getAttributes();
        $this->assertCount(1, $this->storage);
        $this->assertEquals('GET test_route', $this->storage[0]->getName());
        $this->assertEquals('http://:/', $attributes->get(TraceAttributes::URL_FULL));
        $this->assertEquals('GET', $attributes->get(TraceAttributes::HTTP_REQUEST_METHOD));
        $this->assertEquals('http', $attributes->get(TraceAttributes::URL_SCHEME));
        $this->assertEquals('test_route', $attributes->get(TraceAttributes::HTTP_ROUTE));
        $this->assertEquals(200, $attributes->get(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertEquals('1.0', $attributes->get(TraceAttributes::NETWORK_PROTOCOL_VERSION));
        $this->assertEquals(5, $attributes->get(TraceAttributes::HTTP_RESPONSE_BODY_SIZE));

    }

    public function test_http_kernel_handle_stream_response(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher(), fn () => new StreamedResponse(function () {
            echo 'Hello';
            flush();
        }));
        $this->assertCount(0, $this->storage);

        $response = $kernel->handle(new Request());
        $kernel->terminate(new Request(), $response);

        $this->assertCount(1, $this->storage);
        $this->assertNull($this->storage[0]->getAttributes()->get(TraceAttributes::HTTP_RESPONSE_BODY_SIZE));
    }

    public function test_http_kernel_handle_binary_file_response(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher(), fn () => new BinaryFileResponse(__FILE__));
        $this->assertCount(0, $this->storage);

        $response = $kernel->handle(new Request());
        $kernel->terminate(new Request(), $response);

        $this->assertCount(1, $this->storage);
        $this->assertNull($this->storage[0]->getAttributes()->get(TraceAttributes::HTTP_RESPONSE_BODY_SIZE));

    }

    public function test_http_kernel_handle_with_empty_route(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher());
        $this->assertCount(0, $this->storage);
        $request = new Request();
        $request->attributes->set('_route', '');

        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $kernel->terminate(new Request(), $response);

        $this->assertCount(1, $this->storage);
        $this->assertFalse($this->storage[0]->getAttributes()->has(TraceAttributes::HTTP_ROUTE));

    }

    public function test_http_kernel_handle_without_route(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher());
        $this->assertCount(0, $this->storage);

        $response = $kernel->handle(new Request(), HttpKernelInterface::MAIN_REQUEST, true);
        $kernel->terminate(new Request(), $response);

        $this->assertCount(1, $this->storage);
        $this->assertFalse($this->storage[0]->getAttributes()->has(TraceAttributes::HTTP_ROUTE));

    }

    public function test_http_kernel_handle_subrequest(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher());
        $this->assertCount(0, $this->storage);
        $request = new Request();
        $request->attributes->set('_controller', 'ErrorController');

        // Note: terminate() is not called — sub-requests never receive a terminate() call
        // in Symfony. The span is ended and exported directly from the handle post-hook.
        $kernel->handle($request, HttpKernelInterface::SUB_REQUEST);

        $this->assertCount(1, $this->storage);

        $span = $this->storage[0];
        $this->assertSame('GET ErrorController', $span->getName());
        $this->assertSame(SpanKind::KIND_INTERNAL, $span->getKind());
    }

    public function test_http_kernel_handle_subrequest_with_various_controller_types(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher());

        // String controller
        $request = new Request();
        $request->attributes->set('_controller', 'SomeController::index');
        $kernel->handle($request, \Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST);
        $this->assertSame('GET SomeController::index', $this->storage[0]->getName());
        $this->storage->exchangeArray([]);

        // Array: [object, method]
        $controllerObj = new class() {};
        $request = new Request();
        $request->attributes->set('_controller', [$controllerObj, 'fooAction']);
        $kernel->handle($request, \Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST);
        $this->assertSame('GET ' . get_class($controllerObj) . '::fooAction', $this->storage[0]->getName());
        $this->storage->exchangeArray([]);

        // Array: [class, method]
        $request = new Request();
        $request->attributes->set('_controller', ['SomeClass', 'barAction']);
        $kernel->handle($request, \Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST);
        $this->assertSame('GET SomeClass::barAction', $this->storage[0]->getName());
        $this->storage->exchangeArray([]);
    }

    /**
     * @psalm-suppress UnevaluatedCode
     */
    public function test_http_kernel_handle_subrequest_with_null_and_object_controller(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher());

        // Object controller  (should fallback to 'sub-request')
        $controllerObj2 = new class() {};
        $request = new Request();
        $request->attributes->set('_controller', $controllerObj2);
        $kernel->handle($request, \Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST);
        $this->assertSame('GET sub-request', $this->storage[0]->getName());

        // Null/other controller (should fallback to 'sub-request')
        $request = new Request();
        $request->attributes->set('_controller', null);
        $kernel->handle($request, \Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST);
        $this->assertSame('GET sub-request', $this->storage[0]->getName());
    }

    public function test_main_request_span_is_exported_when_subrequest_occurs(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher());
        $mainRequest = new Request();
        $mainRequest->attributes->set('_route', 'main_route');
        $subRequest = new Request();
        $subRequest->attributes->set('_controller', 'ErrorController');

        // Simulate what Symfony does internally for error responses:
        // handle(MAIN_REQUEST) is called, which internally calls handle(SUB_REQUEST)
        // to render the error page, before terminate() is called.
        $kernel->handle($mainRequest, HttpKernelInterface::MAIN_REQUEST);
        $kernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST);
        $response = new Response('Not Found', 404);
        $kernel->terminate($mainRequest, $response);

        // Both spans must be exported: sub-request span (ended in handle post-hook)
        // and main request span (ended in terminate post-hook).
        $this->assertCount(2, $this->storage);

        $spans = iterator_to_array($this->storage);
        $mainSpan = null;
        $subSpan = null;
        foreach ($spans as $span) {
            if ($span->getKind() === SpanKind::KIND_SERVER) {
                $mainSpan = $span;
            } else {
                $subSpan = $span;
            }
        }

        $this->assertNotNull($mainSpan, 'Main request span (KIND_SERVER) must be exported');
        $this->assertNotNull($subSpan, 'Sub-request span (KIND_INTERNAL) must be exported');

        // Sub-request span must be a child of the main request span
        $this->assertSame(
            $mainSpan->getContext()->getSpanId(),
            $subSpan->getParentSpanId(),
            'Sub-request span must be a child of the main request span'
        );

        // Main request span must be the root (no parent)
        $this->assertSame('0000000000000000', $mainSpan->getParentSpanId());
    }

    public function test_subrequest_scope_does_not_leak_into_next_request(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher());

        // First request: main + sub (simulating error response rendering)
        $mainRequest = new Request();
        $mainRequest->attributes->set('_route', 'first_route');
        $subRequest = new Request();
        $subRequest->attributes->set('_controller', 'ErrorController');

        $kernel->handle($mainRequest, HttpKernelInterface::MAIN_REQUEST);
        $kernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST);
        $kernel->terminate($mainRequest, new Response('', 404));

        $this->assertCount(2, $this->storage);
        $this->storage->exchangeArray([]);

        // Second request: must be independent with no leftover scope from the first
        $secondRequest = new Request();
        $secondRequest->attributes->set('_route', 'second_route');
        $response = $kernel->handle($secondRequest, HttpKernelInterface::MAIN_REQUEST);
        $kernel->terminate($secondRequest, $response);

        $this->assertCount(1, $this->storage);
        $span = $this->storage[0];
        $this->assertSame(SpanKind::KIND_SERVER, $span->getKind());
        // Must be a root span with no parent from the previous request
        $this->assertSame('0000000000000000', $span->getParentSpanId());
    }

    private function getHttpKernel(EventDispatcherInterface $eventDispatcher, $controller = null, ?RequestStack $requestStack = null, array $arguments = []): HttpKernel
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
