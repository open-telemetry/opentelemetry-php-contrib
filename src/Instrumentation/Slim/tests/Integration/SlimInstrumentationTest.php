<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Slim\Integration;

use ArrayObject;
use Mockery;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use OpenTelemetry\API\Common\Instrumentation\Configurator;
use OpenTelemetry\API\Common\Instrumentation\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Interfaces\InvocationStrategyInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Interfaces\RouteResolverInterface;
use Slim\Middleware\RoutingMiddleware;
use Slim\Routing\RouteContext;

class SlimInstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    private ArrayObject $storage;

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

    /**
     * @dataProvider routeProvider
     */
    public function test_routing_updates_root_span_name(RouteInterface $route, string $expected): void
    {
        $span = Globals::tracerProvider()->getTracer('test')->spanBuilder('root')->startSpan();
        $request = (new ServerRequest('GET', 'http://example.com/foo'))->withAttribute(SpanInterface::class, $span);

        $routingMiddleware = new class($this->createMock(RouteResolverInterface::class), $this->createMock(RouteParserInterface::class)) extends RoutingMiddleware {
            public function performRouting(ServerRequestInterface $request): ServerRequestInterface
            {
                return $request;
            }
        };
        $app = $this->createMockApp(
            $this->createMock(ResponseInterface::class),
            $routingMiddleware
        );
        $app->handle($request->withAttribute(RouteContext::ROUTE, $route));
        $span->end();
        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0); // @var ImmutableSpan $span
        $this->assertSame($expected, $span->getName());
    }

    /**
     * @psalm-suppress PossiblyUndefinedMethod
     */
    public function routeProvider(): array
    {
        return [
            'named route' => [
                Mockery::mock(RouteInterface::class)->allows([
                    'getName' => 'route.name',
                ]),
                'GET route.name',
            ],
            'unnamed route' => [
                Mockery::mock(RouteInterface::class)->allows([
                    'getName' => null,
                    'getPattern' => '/books/{id}',
                ]),
                'GET /books/{id}',
            ],
        ];
    }

    public function test_invocation_strategy(): void
    {
        $strategy = $this->createMockStrategy();
        $this->assertCount(0, $this->storage);
        $strategy->__invoke(
            function (): ResponseInterface {
                return new Response();
            },
            $this->createMock(ServerRequestInterface::class),
            $this->createMock(ResponseInterface::class),
            []
        );
        $this->assertCount(1, $this->storage);
    }

    public function test_routing_exception(): void
    {
        $span = Globals::tracerProvider()->getTracer('test')->spanBuilder('root')->startSpan();
        $request = (new ServerRequest('GET', 'http://example.com/foo'));

        $routingMiddleware = new class($this->createMock(RouteResolverInterface::class), $this->createMock(RouteParserInterface::class)) extends RoutingMiddleware {
            public function performRouting(ServerRequestInterface $request): ServerRequestInterface
            {
                throw new \Exception('routing failed');
            }
        };
        $app = $this->createMockApp(
            $this->createMock(ResponseInterface::class),
            $routingMiddleware
        );

        try {
            $app->handle($request);
        } catch (\Exception $e) {
            $this->assertSame('routing failed', $e->getMessage());
        }
        $span->end();
        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0); // @var ImmutableSpan $span
        $this->assertSame('root', $span->getName(), 'span name was not updated because routing failed');
    }

    public function createMockStrategy(): InvocationStrategyInterface
    {
        return new class() implements InvocationStrategyInterface {
            public function __invoke(callable $callable, ServerRequestInterface $request, ResponseInterface $response, array $routeArguments): ResponseInterface
            {
                return $response;
            }
        };
    }

    public function createMockApp(ResponseInterface $response, ?RoutingMiddleware $routingMiddleware = null): App
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        return new class($response, $routingMiddleware, $handler) extends App {
            private ResponseInterface $response;
            private RequestHandlerInterface $handler;
            private ?RoutingMiddleware $routingMiddleware;
            public function __construct(ResponseInterface $response, ?RoutingMiddleware $routingMiddleware, RequestHandlerInterface $handler)
            {
                $this->response = $response;
                $this->routingMiddleware = $routingMiddleware;
                $this->handler = $handler;
            }
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return isset($this->routingMiddleware)
                    ? $this->routingMiddleware->process($request, $this->handler)
                    : $this->response;
            }
        };
    }
}
