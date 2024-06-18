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
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\Tests\Instrumentation\CakePHP\Integration\App\src\Controller\ArticleController;
use OpenTelemetry\Tests\Instrumentation\CakePHP\Integration\App\src\Application;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * @covers \OpenTelemetry\Contrib\Instrumentation\CakePHP\CakePHPInstrumentation
 * @todo Use CakePHP's test framework, https://book.cakephp.org/4/en/development/testing.html
 */
class CakePHPInstrumentationTest extends TestCase
{
    use IntegrationTestTrait;

    private const TRACE_ID = 'ff000000000000000000000000000041';
    private const SPAN_ID = 'ff00000000000041';
    private const TRACEPARENT_HEADER = '00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01';
    private ScopeInterface $scope;
    /** @var ArrayObject<int, ImmutableSpan> $storage */
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
            ->withPropagator(TraceContextPropagator::getInstance())
            ->activate();
        $this->configRequest(['headers'=>['Accept'=>'application/json']]);
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    public function test_index(): void
    {
        $this->assertCount(0, $this->storage);

        $this->configRequest(['headers'=>[
            'Accept'=>'application/json',
            'traceparent' => '01-'. self::TRACE_ID . '-' . self::SPAN_ID . '-ff'
        ]]);

        $this->get('/article');

        print((string) $this->_response->getBody());

        $this->assertCount(1, $this->storage);
        /** @var ImmutableSpan $span */
        $span = $this->storage[0];
        $this->assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
        $this->assertSame('GET', $span->getName());
        $this->assertSame(SpanKind::KIND_SERVER, $span->getKind());
        $this->assertGreaterThan(0, $span->getAttributes()->count());
        $attributes = $span->getAttributes()->toArray();
        $this->assertSame('invokeAction', $attributes['code.function']);
        $this->assertSame('GET', $attributes['http.request.method']);
        $this->assertSame(200, $attributes['http.response.status_code']);
        $this->assertSame(self::TRACE_ID, $span->getParentContext()->getTraceId());
        $this->assertSame(self::SPAN_ID, $span->getParentContext()->getSpanId());
    }

    public function test_exception(): void
    {
        $this->assertCount(0, $this->storage);

        $this->get('/article/exception');

        $this->assertCount(1, $this->storage);
        /** @var ImmutableSpan $span */
        $span = $this->storage[0];
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $event = $span->getEvents()[0];
        $this->assertSame('exception', $event->getName());
        $this->assertSame('kaboom', $event->getAttributes()->get('exception.message'));
    }

    public function test_resource_route()
    {
        $this->get('/article/1234');
        /** @var ImmutableSpan $span */
        $span = $this->storage[0];
        $attributes = $span->getAttributes()->toArray();
        $this->assertSame('/article/{id}', $attributes['http.route']);
    }

    public function test_fallback_route()
    {
        $this->get('/article/update');
        /** @var ImmutableSpan $span */
        $span = $this->storage[0];
        $attributes = $span->getAttributes()->toArray();
        $this->assertSame('/{controller}/{action}/*', $attributes['http.route']);
    }
}