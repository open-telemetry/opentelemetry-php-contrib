<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
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

        // Sub-requests are never passed to terminate() by Symfony; their span is ended
        // by the handle post-hook, so it is exported as soon as handle() returns.
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
        $this->storage->exchangeArray([]);

        // Null/other controller (should fallback to 'sub-request')
        $request = new Request();
        $request->attributes->set('_controller', null);
        $kernel->handle($request, \Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST);
        $this->assertSame('GET sub-request', $this->storage[0]->getName());
    }

    public function test_http_kernel_handle_uncaught_exception_ends_span(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher(), function () {
            throw new \RuntimeException('something went wrong');
        });

        try {
            $kernel->handle(new Request());
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertCount(1, $this->storage);
        $span = $this->storage[0];
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('something went wrong', $span->getStatus()->getDescription());
    }

    public function test_http_kernel_handle_5xx_response_is_error(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher(), fn () => new Response('Internal Server Error', 500));

        $response = $kernel->handle(new Request());
        $kernel->terminate(new Request(), $response);

        $this->assertCount(1, $this->storage);
        $span = $this->storage[0];
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(500, $span->getAttributes()->get(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));
    }

    public function test_http_kernel_sub_request_scope_does_not_leak_main_span(): void
    {
        // Simulates Symfony's internal error-controller dispatch: a MAIN_REQUEST handle()
        // call whose exception is caught internally, followed by a nested SUB_REQUEST
        // handle() call that renders the error response successfully (no exception).
        $kernel = $this->getHttpKernel(new EventDispatcher(), fn () => new Response('error page'));
        $this->assertCount(0, $this->storage);

        $mainRequest = new Request();
        $mainRequest->attributes->set('_route', 'main_route');
        $kernel->handle($mainRequest, HttpKernelInterface::MAIN_REQUEST, false);

        // The sub-request is dispatched while the main request span's scope is still on
        // the context storage stack, exactly as Symfony's ErrorListener does it.
        $subRequest = new Request();
        $subRequest->attributes->set('_controller', 'ErrorController');
        $subResponse = $kernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST);

        // The sub-request span must be ended and exported immediately: it never reaches
        // `terminate()`, so leaving its scope attached would leak it and block the main
        // request span from ever being reached again.
        $this->assertCount(1, $this->storage);
        $this->assertSame('GET ErrorController', $this->storage[0]->getName());
        $this->assertSame(SpanKind::KIND_INTERNAL, $this->storage[0]->getKind());

        // `terminate()` must now see the main request's scope, not a leaked sub-request one.
        $kernel->terminate($mainRequest, $subResponse);

        $this->assertCount(2, $this->storage);
        $mainSpan = $this->storage[1];
        $this->assertSame('GET main_route', $mainSpan->getName());
        $this->assertSame(SpanKind::KIND_SERVER, $mainSpan->getKind());
    }

    public function test_http_kernel_records_exception_on_main_span_during_sub_request_error_handling(): void
    {
        $kernel = $this->getHttpKernel(new EventDispatcher(), fn () => new Response('error page'));

        $mainRequest = new Request();
        $kernel->handle($mainRequest, HttpKernelInterface::MAIN_REQUEST, false);

        // Symfony's HttpKernel::handleThrowable() records the exception on whatever span
        // is currently active before it dispatches the internal error sub-request; at
        // that point the only span on the stack is the main request's.
        \OpenTelemetry\API\Trace\Span::getCurrent()->recordException(new \RuntimeException('boom'));

        $subRequest = new Request();
        $subRequest->attributes->set('_controller', 'ErrorController');
        $subResponse = $kernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST);
        $kernel->terminate($mainRequest, $subResponse);

        $this->assertCount(2, $this->storage);
        $mainSpan = $this->storage[1];
        $this->assertSame(SpanKind::KIND_SERVER, $mainSpan->getKind());
        $this->assertCount(1, $mainSpan->getEvents());
        $this->assertSame('exception', $mainSpan->getEvents()[0]->getName());
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
