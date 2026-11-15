<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\Psr15\tests\Integration;

use ArrayObject;
use Exception;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class Psr15InstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    private ArrayObject $storage;
    private ServerRequestInterface $request;
    private TracerProvider $tracerProvider;

    public function setUp(): void
    {
        $this->storage = new ArrayObject();
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage)
            )
        );
        $this->request = new ServerRequest(
            'GET',
            new Uri('http://example.com/foo'),
            [],
            'body',
            '1.1',
        );

        $this->scope = Configurator::create()
            ->withTracerProvider($this->tracerProvider)
            ->withPropagator(new TraceContextPropagator())
            ->activate();
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    public function test_request_handler(): void
    {
        $handler = $this->createHandler();
        $this->assertCount(0, $this->storage);
        $handler->handle($this->request);
        $this->assertCount(1, $this->storage);
    }

    public function test_handler_with_trace_context_propagation(): void
    {
        $traceId = 'ff000000000000000000000000000041';
        $spanId = 'ff00000000000041';
        $traceParent = '00-' . $traceId . '-' . $spanId . '-01';
        $request = $this->request->withHeader('traceparent', $traceParent);
        $handler = $this->createHandler();
        $handler->handle($request);
        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0); // @var \OpenTelemetry\SDK\Trace\Span $span
        $this->assertTrue($span->getParentContext()->isRemote());
        $this->assertSame($traceId, $span->getParentContext()->getTraceId());
        $this->assertSame($spanId, $span->getParentContext()->getSpanId());
        $this->assertTrue($span->getParentContext()->isSampled());
    }

    public function test_request_handler_when_span_already_in_request_attributes(): void
    {
        $root = $this->tracerProvider->getTracer('test')->spanBuilder('root')->startSpan();
        $scope = $root->activate();
        $handler = $this->createHandler();
        $request = $this->request->withAttribute(SpanInterface::class, $root);
        $handler->handle($request);
        $span = $this->storage->offsetGet(0); // @var \OpenTelemetry\SDK\Trace\Span $span
        $this->assertSame($root->getContext()->getTraceId(), $span->getContext()->getTraceId());
        $this->assertNotSame($root->getContext()->getSpanId(), $span->getContext()->getSpanId());
        $scope->detach();
        $root->end();
    }

    public function test_request_handler_with_exception(): void
    {
        $handler = $this->createHandler(new Exception('foo'));

        try {
            $handler->handle($this->request);
        } catch (\Exception $e) {
            $this->assertSame('foo', $e->getMessage());
        }
        // @var ImmutableSpan $span
        $span = $this->storage->offsetGet(0);
        $this->assertSame('foo', $span->getStatus()->getDescription());
        $this->assertSame('exception', $span->getEvents()[0]->getName());
    }

    public function test_middleware_process(): void
    {
        $middleware = new class() implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return new Response();
            }
        };
        $handler = $this->createMock(RequestHandlerInterface::class);
        $this->assertCount(0, $this->storage);
        $middleware->process($this->request, $handler);
        $this->assertCount(1, $this->storage);
    }

    public function test_middleware_process_with_exception(): void
    {
        $middleware = new class() implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                throw new \Exception('foo');
            }
        };
        $handler = $this->createMock(RequestHandlerInterface::class);
        $this->assertCount(0, $this->storage);

        try {
            $middleware->process($this->request, $handler);
        } catch (\Exception $e) {
            $this->assertSame('foo', $e->getMessage());
        }
        $this->assertCount(1, $this->storage);
        // @var ImmutableSpan $span
        $span = $this->storage->offsetGet(0);
        $this->assertSame('foo', $span->getStatus()->getDescription());
        $this->assertSame('exception', $span->getEvents()[0]->getName());
    }

    public function test_middleware_span_name_for_anonymous_class(): void
    {
        $middleware = new class() implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return new Response();
            }
        };
        $middleware->process($this->request, $this->createMock(RequestHandlerInterface::class));

        $this->assertCount(1, $this->storage);
        // @var ImmutableSpan $span
        $span = $this->storage->offsetGet(0);
        $expected = 'Psr\Http\Server\MiddlewareInterface@anonymous::process';
        $this->assertSame($expected, $span->getName());
        $this->assertSame($expected, $span->getAttributes()->get(CodeAttributes::CODE_FUNCTION_NAME));
    }

    public function test_request_handler_span_name_is_stable_across_anonymous_classes(): void
    {
        $root = $this->tracerProvider->getTracer('test')->spanBuilder('root')->startSpan();
        $scope = $root->activate();
        $request = $this->request->withAttribute(SpanInterface::class, $root);
        $first = $this->createHandler();
        $second = new class() implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };
        // Each anonymous class name embeds its file, line and a compile counter after a NUL byte.
        $this->assertNotSame($first::class, $second::class);

        $first->handle($request);
        $second->handle($request);
        $scope->detach();
        $root->end();

        $expected = 'Psr\Http\Server\RequestHandlerInterface@anonymous::handle';
        foreach ([0, 1] as $offset) {
            // @var ImmutableSpan $span
            $span = $this->storage->offsetGet($offset);
            $this->assertSame($expected, $span->getName());
            $this->assertSame($expected, $span->getAttributes()->get(CodeAttributes::CODE_FUNCTION_NAME));
        }
    }

    private function createHandler(?Exception $e = null): RequestHandlerInterface
    {
        return new class($e) implements RequestHandlerInterface {
            private ?Exception $exception;
            public function __construct(?Exception $exception = null)
            {
                $this->exception = $exception;
            }
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                if ($this->exception) {
                    throw $this->exception;
                }
                $span = $request->getAttribute(SpanInterface::class);
                Assert::assertInstanceOf(Span::class, $span);

                return new Response();
            }
        };
    }
}
