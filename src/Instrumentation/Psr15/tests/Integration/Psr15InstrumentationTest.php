<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\Psr15\tests\Integration;

use ArrayObject;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use OpenTelemetry\API\Common\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Contrib\Instrumentation\Psr15\Psr15Instrumentation;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
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

    public function setUp(): void
    {
        $this->storage = new ArrayObject();
        $tracerProvider = new TracerProvider(
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
            ->withTracerProvider($tracerProvider)
            ->activate();
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    public function test_request_handler(): void
    {
        $handler = new class() implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $span = $request->getAttribute(Psr15Instrumentation::ROOT_SPAN);
                Assert::assertInstanceOf(Span::class, $span);

                return new Response();
            }
        };
        $this->assertCount(0, $this->storage);
        $handler->handle($this->request);
        $this->assertCount(1, $this->storage);
    }

    public function test_request_handler_with_exception(): void
    {
        $handler = new class() implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \Exception('foo');
            }
        };

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
}
