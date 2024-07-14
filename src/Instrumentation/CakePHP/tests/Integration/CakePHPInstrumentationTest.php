<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\CakePHP\Integration;

use Cake\TestSuite\IntegrationTestTrait;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Trace\ImmutableSpan;

/**
 * @covers \OpenTelemetry\Contrib\Instrumentation\CakePHP\CakePHPInstrumentation
 */
class CakePHPInstrumentationTest extends TestCase
{
    use IntegrationTestTrait;

    private const TRACE_ID = 'ff000000000000000000000000000041';
    private const SPAN_ID = 'ff00000000000041';
    private const TRACEPARENT_HEADER = '00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01';

    public function setUp(): void
    {
        parent::setUp();
        $this->configRequest(['headers'=>['Accept'=>'application/json']]);
    }

    public function test_index(): void
    {
        $this->assertCount(0, $this->storage);

        $this->configRequest(['headers'=>[
            'Accept'=>'application/json',
            'traceparent' => '01-' . self::TRACE_ID . '-' . self::SPAN_ID . '-ff',
        ]]);

        $this->get('/article');

        $this->assertCount(2, $this->storage);
        /** @var ImmutableSpan $span */
        $serverSpan = $this->storage[1];
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
        $controllerSpan = $this->storage[0];
        $this->assertSame(StatusCode::STATUS_UNSET, $controllerSpan->getStatus()->getCode());
        $this->assertSame('Cake\Controller\Controller::invokeAction', $controllerSpan->getName());
        $this->assertSame(SpanKind::KIND_INTERNAL, $controllerSpan->getKind());
        $this->assertGreaterThan(0, $controllerSpan->getAttributes()->count());
        $attributes = $controllerSpan->getAttributes()->toArray();
        $this->assertSame('invokeAction', $attributes['code.function']);
        $this->assertSame($serverSpan->getTraceId(), $controllerSpan->getParentContext()->getTraceId());
        $this->assertSame($serverSpan->getSpanId(), $controllerSpan->getParentContext()->getSpanId());
    }

    public function test_exception(): void
    {
        $this->assertCount(0, $this->storage);

        $this->get('/article/exception');

        $this->assertCount(2, $this->storage);
        /** @var ImmutableSpan $span */
        $span = $this->storage[0];
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $event = $span->getEvents()[0];
        $this->assertSame('exception', $event->getName());
        $this->assertSame('kaboom', $event->getAttributes()->get('exception.message'));

        /** @var ImmutableSpan $span */
        $serverSpan = $this->storage[1];
        $this->assertSame(StatusCode::STATUS_ERROR, $serverSpan->getStatus()->getCode());
        $event = $serverSpan->getEvents()[0];
        $this->assertSame('exception', $event->getName());
        $this->assertSame('kaboom', $event->getAttributes()->get('exception.message'));
    }

    public function test_low_cardinality_route_attribute(): void
    {
        $this->get('/article/1234');

        /** @var ImmutableSpan $span */
        $span = $this->storage[1];
        $attributes = $span->getAttributes()->toArray();
        $this->assertSame('/article/{id}', $attributes['http.route']);
    }

    public function test_fallback_route(): void
    {
        $this->get('/article/update');

        /** @var ImmutableSpan $span */
        $span = $this->storage[1];
        $attributes = $span->getAttributes()->toArray();
        $this->assertSame('/{controller}/{action}/*', $attributes['http.route']);
    }

    public function test_response_code_gte_400(): void
    {
        $this->assertCount(0, $this->storage);

        $this->get('/article/clientErrorResponse');

        $this->assertCount(2, $this->storage);
        /** @var ImmutableSpan $span */
        $span = $this->storage[0];
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $events = $span->getEvents();
        $this->assertCount(0, $events);
        $attributes = $span->getAttributes()->toArray();
        $this->assertSame(400, $attributes['http.response.status_code']);

        /** @var ImmutableSpan $span */
        $serverSpan = $this->storage[1];
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $events = $span->getEvents();
        $this->assertCount(0, $events);
        $attributes = $span->getAttributes()->toArray();
        $this->assertSame(400, $attributes['http.response.status_code']);
    }
}