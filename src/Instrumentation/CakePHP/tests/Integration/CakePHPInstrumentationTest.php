<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\CakePHP\Integration;

use ArrayObject;
use Cake\Core\Configure;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\TestSuite\IntegrationTestTrait;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Common\Export\Stream\StreamTransportFactory;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\ConsoleMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporter;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\Tests\Instrumentation\CakePHP\Integration\App\src\Controller\ArticleController;
use OpenTelemetry\Tests\Instrumentation\CakePHP\Integration\App\src\Application;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * @covers \OpenTelemetry\Contrib\Instrumentation\CakePHP\CakePHPInstrumentation
 */
class CakePHPInstrumentationTest extends TestCase
{
    use IntegrationTestTrait;

    private const TRACE_ID = 'ff000000000000000000000000000041';
    private const SPAN_ID = 'ff00000000000041';
    private const TRACEPARENT_HEADER = '00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01';
    private ScopeInterface $scope;
    /** @var ArrayObject<int, ImmutableSpan> $traceStorage */
    private ArrayObject $traceStorage;

    private ArrayObject $metricStorage;
    private \OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter $metricExporter;

    public function setUp(): void
    {
        $this->traceStorage = new ArrayObject();
        $this->metricStorage = new ArrayObject();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->traceStorage),
            )
        );

        $this->metricExporter = new \OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter();
        $this->metricReader = new ExportingReader($this->metricExporter);
        $meterProvider = MeterProvider::builder()
            ->addReader($this->metricReader)
            ->build();

        $this->scope = Configurator::create()
            ->withTracerProvider($tracerProvider)
            ->withPropagator(TraceContextPropagator::getInstance())
            ->withMeterProvider($meterProvider)
            ->activate();
        $this->configRequest(['headers'=>['Accept'=>'application/json']]);
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    public function test_index(): void
    {
        $this->assertCount(0, $this->traceStorage);

        $this->configRequest(['headers'=>[
            'Accept'=>'application/json',
            'traceparent' => '01-'. self::TRACE_ID . '-' . self::SPAN_ID . '-ff'
        ]]);

        $this->get('/article');

        $this->assertCount(2, $this->traceStorage);
        /** @var ImmutableSpan $span */
        $serverSpan = $this->traceStorage[1];
        $this->assertSame(StatusCode::STATUS_UNSET, $serverSpan->getStatus()->getCode());
        $this->assertSame('GET', $serverSpan->getName());
        $this->assertSame(SpanKind::KIND_SERVER, $serverSpan->getKind());
        $this->assertGreaterThan(0, $serverSpan->getAttributes()->count());
        $attributes = $serverSpan->getAttributes()->toArray();
        $this->assertSame('run', $attributes['code.function']);
        $this->assertSame('GET', $attributes['http.request.method']);
        $this->assertSame(200, $attributes['http.response.status_code']);
        $this->assertSame(self::TRACE_ID, $serverSpan->getParentContext()->getTraceId());
        $this->assertSame(self::SPAN_ID, $serverSpan->getParentContext()->getSpanId());

        /** @var ImmutableSpan $span */
        $controllerSpan = $this->traceStorage[0];
        $this->assertSame(StatusCode::STATUS_UNSET, $controllerSpan->getStatus()->getCode());
        $this->assertSame('Cake\Controller\Controller::invokeAction', $controllerSpan->getName());
        $this->assertSame(SpanKind::KIND_INTERNAL, $controllerSpan->getKind());
        $this->assertGreaterThan(0, $controllerSpan->getAttributes()->count());
        $attributes = $controllerSpan->getAttributes()->toArray();
        $this->assertSame('invokeAction', $attributes['code.function']);
        $this->assertSame($serverSpan->getTraceId(), $controllerSpan->getParentContext()->getTraceId());
        $this->assertSame($serverSpan->getSpanId(), $controllerSpan->getParentContext()->getSpanId());

        $this->metricReader->collect();
        $metrics = $this->metricExporter->collect();
        /** @var Histogram $metric */
        $metric = $metrics[0]->data;
        $this->assertSame('http.server.request.duration', $metrics[0]->name);
        $this->assertGreaterThan(0, $metric->dataPoints[0]->attributes->count());
        $metricAttributes = $metric->dataPoints[0]->attributes->toArray();
        $this->assertSame('GET' , $metricAttributes['http.request.method']);
        $this->assertSame('/article', $metricAttributes['http.route']);
        $this->assertSame(200, $metricAttributes['http.response.status_code']);
    }

    public function test_exception(): void
    {
        $this->assertCount(0, $this->traceStorage);

        $this->get('/article/exception');

        $this->assertCount(2, $this->traceStorage);
        /** @var ImmutableSpan $span */
        $span = $this->traceStorage[0];
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $event = $span->getEvents()[0];
        $this->assertSame('exception', $event->getName());
        $this->assertSame('kaboom', $event->getAttributes()->get('exception.message'));

        /** @var ImmutableSpan $span */
        $serverSpan = $this->traceStorage[1];
        $this->assertSame(StatusCode::STATUS_ERROR, $serverSpan->getStatus()->getCode());
        $event = $serverSpan->getEvents()[0];
        $this->assertSame('exception', $event->getName());
        $this->assertSame('kaboom', $event->getAttributes()->get('exception.message'));

        $this->metricReader->collect();
        $metrics = $this->metricExporter->collect();
        /** @var Histogram $metric */
        $metric = $metrics[0]->data;
        $this->assertSame('http.server.request.duration', $metrics[0]->name);
        $this->assertGreaterThan(0, $metric->dataPoints[0]->attributes->count());
        $metricAttributes = $metric->dataPoints[0]->attributes->toArray();
        $this->assertSame('GET' , $metricAttributes['http.request.method']);
        $this->assertSame('/{controller}/{action}/*', $metricAttributes['http.route']);
        $this->assertSame('RuntimeException', $metricAttributes['error.type']);
    }

    public function test_resource_route(): void
    {
        $this->get('/article/1234');

        /** @var ImmutableSpan $span */
        $span = $this->traceStorage[1];
        $attributes = $span->getAttributes()->toArray();
        $this->assertSame('/article/{id}', $attributes['http.route']);

        $this->metricReader->collect();
        $metrics = $this->metricExporter->collect();
        /** @var Histogram $metric */
        $metric = $metrics[0]->data;
        $metricAttributes = $metric->dataPoints[0]->attributes->toArray();
        $this->assertSame('/article/{id}', $metricAttributes['http.route']);
    }

    public function test_fallback_route(): void
    {
        $this->get('/article/update');

        /** @var ImmutableSpan $span */
        $span = $this->traceStorage[1];
        $attributes = $span->getAttributes()->toArray();
        $this->assertSame('/{controller}/{action}/*', $attributes['http.route']);

        $this->metricReader->collect();
        $metrics = $this->metricExporter->collect();
        /** @var Histogram $metric */
        $metric = $metrics[0]->data;
        $metricAttributes = $metric->dataPoints[0]->attributes->toArray();
        $this->assertSame('/{controller}/{action}/*', $metricAttributes['http.route']);
    }

    public function test_response_code_gte_400(): void
    {
        $this->assertCount(0, $this->traceStorage);

        $this->get('/article/clientErrorResponse');

        $this->assertCount(2, $this->traceStorage);
        /** @var ImmutableSpan $span */
        $span = $this->traceStorage[0];
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $events = $span->getEvents();
        $this->assertCount(0, $events);
        $attributes = $span->getAttributes()->toArray();
        $this->assertSame(400, $attributes['http.response.status_code']);

        /** @var ImmutableSpan $span */
        $serverSpan = $this->traceStorage[1];
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $events = $span->getEvents();
        $this->assertCount(0, $events);
        $attributes = $span->getAttributes()->toArray();
        $this->assertSame(400, $attributes['http.response.status_code']);

        $this->metricReader->collect();
        $metrics = $this->metricExporter->collect();
        /** @var Histogram $metric */
        $metric = $metrics[0]->data;
        $metricAttributes = $metric->dataPoints[0]->attributes->toArray();
        $this->assertSame(400, $metricAttributes['error.type']);
    }

    public function test_add(): void
    {
        $this->assertCount(0, $this->traceStorage);
        $this->metricReader->collect();
        $metrics = $this->metricExporter->collect();
        $this->assertCount(0, $metrics);

        $this->post('/article', 'test123');

        $this->metricReader->collect();
        $metrics = $this->metricExporter->collect();
        $this->assertCount(2, $metrics);
        $this->assertSame('http.server.request.duration', $metrics[0]->name);
        $this->assertSame('http.server.request.body.size', $metrics[1]->name);
    }

    public function test_body(): void
    {
        $this->assertCount(0, $this->traceStorage);
        $this->metricReader->collect();
        $metrics = $this->metricExporter->collect();
        $this->assertCount(0, $metrics);

        $this->get('/article/body');

        $this->metricReader->collect();
        $metrics = $this->metricExporter->collect();
        $this->assertCount(2, $metrics);
        $this->assertSame('http.server.request.duration', $metrics[0]->name);
        $this->assertSame('http.server.response.body.size', $metrics[1]->name);
    }
}