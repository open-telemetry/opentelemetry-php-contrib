<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Slim\Integration;

use ArrayObject;
use Mockery;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Contrib\Instrumentation\Slim\PsrServerRequestMetrics;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricsExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\TraceAttributes;
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
    private ArrayObject $traces;
    private ArrayObject $metrics;
    private ExportingReader $reader;

    public function setUp(): void
    {
        $this->traces = new ArrayObject();
        $this->metrics = new ArrayObject();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->traces)
            )
        );
        $this->reader = new ExportingReader(new InMemoryMetricsExporter($this->metrics));
        $meterProvider = MeterProvider::builder()->addReader($this->reader)->build();

        $this->scope = Configurator::create()
            ->withTracerProvider($tracerProvider)
            ->withMeterProvider($meterProvider)
            ->activate();
    }

    public function tearDown(): void
    {
        $this->scope->detach();
        PsrServerRequestMetrics::reset();
    }

    /**
     * @dataProvider routeProvider
     */
    public function test_routing_updates_root_span_name(RouteInterface $route, string $expected): void
    {
        $request = new ServerRequest('GET', 'http://example.com/foo');

        $routingMiddleware = new class($this->createMock(RouteResolverInterface::class), $this->createMock(RouteParserInterface::class)) extends RoutingMiddleware {
            public function performRouting(ServerRequestInterface $request): ServerRequestInterface
            {
                return $request;
            }
        };
        $app = $this->createMockApp(
            new Response(),
            $routingMiddleware
        );
        $app->handle($request->withAttribute(RouteContext::ROUTE, $route));
        $this->assertCount(1, $this->traces);
        $span = $this->traces->offsetGet(0); // @var ImmutableSpan $span
        $this->assertSame($expected, $span->getName());
    }

    /**
     * @psalm-suppress UndefinedInterfaceMethod
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
        $this->assertCount(0, $this->traces);
        $strategy->__invoke(
            function (): ResponseInterface {
                return new Response();
            },
            $this->createMock(ServerRequestInterface::class),
            $this->createMock(ResponseInterface::class),
            []
        );
        $this->assertCount(1, $this->traces);
    }

    public function test_routing_exception(): void
    {
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
        $this->assertCount(1, $this->traces);
        $span = $this->traces->offsetGet(0); // @var ImmutableSpan $span
        $this->assertSame('GET', $span->getName(), 'span name was not updated because routing failed');
    }

    public function test_response_propagation(): void
    {
        $otelVersion = phpversion('opentelemetry');
        if ($otelVersion == false || version_compare($otelVersion, '1.0.2beta2') < 0) {
            $this->markTestSkipped('response propagation requires opentelemetry extension >= 1.0.2beta2');
        }
        $request = (new ServerRequest('GET', 'https://example.com/foo'));
        $app = $this->createMockApp(new Response(200, ['X-Foo' => 'foo']));
        $response = $app->handle($request);
        $this->assertCount(1, $this->traces);
        $this->assertArrayHasKey('X-Foo', $response->getHeaders());
        $this->assertArrayHasKey('server-timing', $response->getHeaders());
        $this->assertStringStartsWith('traceparent;desc=', $response->getHeaderLine('server-timing'));
        $this->assertArrayHasKey('traceresponse', $response->getHeaders());
    }

    /**
     * @psalm-suppress NoInterfaceProperties
     */
    public function test_generate_metrics(): void
    {
        $request = (new ServerRequest(
            method: 'GET',
            uri: 'http://example.com/foo',
            serverParams: [
                'REQUEST_TIME_FLOAT' => microtime(true),
            ],
        ))->withHeader('Content-Length', '999');
        $route = Mockery::mock(RouteInterface::class)->allows([
            'getName' => 'route.name',
            'getPattern' => '/foo',
        ]);

        $routingMiddleware = new class($this->createMock(RouteResolverInterface::class), $this->createMock(RouteParserInterface::class)) extends RoutingMiddleware {
            public function performRouting(ServerRequestInterface $request): ServerRequestInterface
            {
                return $request;
            }
        };
        $app = $this->createMockApp(
            (new Response())->withHeader('Content-Length', '999'),
            $routingMiddleware
        );
        //execute twice to generate 2 data point  values
        $app->handle($request->withAttribute(RouteContext::ROUTE, $route));
        $app->handle($request->withAttribute(RouteContext::ROUTE, $route));
        $this->assertCount(0, $this->metrics);
        $this->reader->collect();
        $this->assertCount(1, $this->metrics);
        $metric = $this->metrics->offsetGet(0);
        assert($metric instanceof \OpenTelemetry\SDK\Metrics\Data\Metric);
        $this->assertSame('http.server.request.duration', $metric->name);
        $this->assertCount(1, $metric->data->dataPoints);
        $dataPoint = $metric->data->dataPoints[0];
        assert($dataPoint instanceof HistogramDataPoint);
        $this->assertSame(2, $dataPoint->count);
        $attributes = $dataPoint->attributes->toArray();
        $this->assertEqualsCanonicalizing([
            TraceAttributes::HTTP_REQUEST_METHOD => 'GET',
            TraceAttributes::URL_SCHEME => 'http',
            TraceAttributes::HTTP_RESPONSE_BODY_SIZE => 999,
            TraceAttributes::NETWORK_PROTOCOL_VERSION => '1.1',
            TraceAttributes::HTTP_RESPONSE_STATUS_CODE => 200,
        ], $attributes);
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

        /**
         * @psalm-suppress MissingTemplateParam
         */
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
