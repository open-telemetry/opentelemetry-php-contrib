<?php

declare(strict_types=1);

use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Contrib\Shim\OpenTracing\ScopeManager;
use OpenTelemetry\Contrib\Shim\OpenTracing\Tracer;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTracing\GlobalTracer;
use const OpenTracing\Tags\SPAN_KIND;
use PHPUnit\Framework\TestCase;

class OpenTracingTest extends TestCase
{
    private const TRACE_ID = 'ff000000000000000000000000000041';
    private const SPAN_ID = 'ff00000000000041';
    private ArrayObject $storage;
    private Tracer $tracer;

    public function setUp(): void
    {
        $this->storage = new ArrayObject();
        $exporter = new InMemoryExporter($this->storage);
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($exporter));
        $this->tracer = new Tracer($tracerProvider);
        GlobalTracer::set($this->tracer);
    }

    public function test_create_span_with_remote_parent(): void
    {
        $headers = [
            'traceparent' => sprintf('00-%s-%s-01', self::TRACE_ID, self::SPAN_ID),
        ];

        $parent = $this->tracer->extract(OpenTracing\Formats\TEXT_MAP, $headers);

        $this->tracer->startSpan('test', ['child_of' => $parent])->finish();
        $this->assertCount(1, $this->storage);
        // @var \OpenTelemetry\SDK\Trace\ImmutableSpan $span
        $span = $this->storage[0];
        $this->assertSame(self::TRACE_ID, $span->getContext()->getTraceId());
        $this->assertNotSame(self::SPAN_ID, $span->getContext()->getSpanId());
        $this->assertSame(self::TRACE_ID, $span->getParentContext()->getTraceId());
        $this->assertSame(self::SPAN_ID, $span->getParentContext()->getSpanId());
        $this->assertTrue($span->getParentContext()->isRemote());
    }

    public function test_create_active_span(): void
    {
        $scope = $this->tracer->startActiveSpan('test');
        $span = $scope->getSpan();
        $this->assertSame($span, $this->tracer->getActiveSpan());
    }

    public function test_create_child_from_parent(): void
    {
        $parent = $this->tracer->startSpan('parent');
        $this->tracer->startSpan('child', ['child_of' => $parent])->finish();
        $parent->finish();
        // @var \OpenTelemetry\SDK\Trace\ImmutableSpan $childSpan
        $childSpan = $this->storage[0];
        // @var \OpenTelemetry\SDK\Trace\ImmutableSpan $parentSpan
        $parentSpan = $this->storage[1];
        $this->assertSame('child', $childSpan->getName());
        $this->assertSame('parent', $parentSpan->getName());
        $this->assertSame($parentSpan->getTraceId(), $childSpan->getTraceId());
        $this->assertSame($parentSpan->getSpanId(), $childSpan->getParentSpanId());
    }

    public function test_create_child_from_active_span(): void
    {
        $scope = $this->tracer->startActiveSpan('parent');
        $parent = $scope->getSpan();
        $this->tracer->startSpan('child')->finish();
        $parent->finish();
        // @var \OpenTelemetry\SDK\Trace\ImmutableSpan $childSpan
        $childSpan = $this->storage[0];
        // @var \OpenTelemetry\SDK\Trace\ImmutableSpan $parentSpan
        $parentSpan = $this->storage[1];
        $this->assertSame('child', $childSpan->getName());
        $this->assertSame('parent', $parentSpan->getName());
        $this->assertSame($parentSpan->getTraceId(), $childSpan->getTraceId());
        $this->assertSame($parentSpan->getSpanId(), $childSpan->getParentSpanId());
    }

    public function test_span_features(): void
    {
        $span = $this->tracer->startSpan('foo');
        $this->assertSame('foo', $span->getOperationName());
        $span->overwriteOperationName('foo-updated');
        $this->assertSame('foo-updated', $span->getOperationName());
        $span->addBaggageItem('shim', 'baggage-1');
        $span->setTag('attr_one', 'foo');
        $span->setTag('attr_two', false);
        $span->setTag(SPAN_KIND, 'server');
        $span->log(['foo' => 'bar', 'baz' => 'bat']);
        $span->log(['foo' => 'bar'], new \DateTimeImmutable('1984-05-12 13:14:15')); //accepts DateTime
        $span->finish();

        /** @var \OpenTelemetry\SDK\Trace\ImmutableSpan $exported */
        $exported = $this->storage[0];
        $this->assertSame('foo-updated', $exported->getName());
        $this->assertCount(3, $exported->getAttributes());
        $this->assertEquals([
            'attr_one' => 'foo',
            'attr_two' => 'false', //boolean converted to string
            SPAN_KIND => 'server',
        ], $exported->getAttributes()->toArray());
        //logs converted to events
        $this->assertCount(2, $exported->getEvents());
        $event = $exported->getEvents()[0];
        $this->assertStringContainsString('log', $event->getName());
        $this->assertEquals([
            'foo' => 'bar',
            'baz' => 'bat',
        ], $event->getAttributes()->toArray());
    }

    public function test_baggage(): void
    {
        $span = $this->tracer->startSpan('test');
        $span->addBaggageItem('foo', 'bar');
        $span->addBaggageItem('baz', 'bat');
        $this->assertSame('bar', $span->getBaggageItem('foo'));
        $this->assertSame('bat', $span->getBaggageItem('baz'));
        $context = $span->getContext();
        $this->assertCount(2, $context->getIterator());
    }

    /**
     * @dataProvider formatProvider
     */
    public function test_inject_context(string $format): void
    {
        $span = $this->tracer->startSpan('foo');
        $carrier = [];
        $this->tracer->inject($span->getContext(), $format, $carrier);
        $span->finish();
        // @var \OpenTelemetry\SDK\Trace\ImmutableSpan $exported
        $exported = $this->storage[0];
        $parts = explode('-', $carrier['traceparent']);
        $this->assertCount(4, $parts);
        $this->assertSame($exported->getTraceId(), $parts[1]);
        $this->assertSame($exported->getSpanId(), $parts[2]);
    }

    public static function formatProvider(): array
    {
        return [
            [OpenTracing\Formats\TEXT_MAP],
            [OpenTracing\Formats\HTTP_HEADERS],
        ];
    }

    public function test_close_scope(): void
    {
        $scope = $this->tracer->startActiveSpan('foo');
        $this->assertCount(0, $this->storage);
        $scope->close();
        $this->assertCount(1, $this->storage);
    }

    public function test_double_close(): void
    {
        $scope = $this->tracer->startActiveSpan('foo');
        $scope->close();
        $scope->close();
        $this->assertCount(1, $this->storage);
    }

    public function test_close_scope_returns_previous_active(): void
    {
        $scope = $this->tracer->startActiveSpan('parent');
        $parent = $scope->getSpan();
        $scope_two = $this->tracer->startActiveSpan('child');
        $child = $scope_two->getSpan();
        $this->assertSame($child, $this->tracer->getActiveSpan());
        $scope_two->close();
        $this->assertSame($parent, $this->tracer->getActiveSpan());
    }

    public function test_close_scope_when_parent_is_already_closed(): void
    {
        $scope_one = $this->tracer->startActiveSpan('one');
        $scope_two = $this->tracer->startActiveSpan('two');
        $scope_three = $this->tracer->startActiveSpan('three');
        $this->assertSame($scope_three->getSpan(), $this->tracer->getActiveSpan(), 'three is active');
        $scope_two->close();
        $this->assertSame($scope_three->getSpan(), $this->tracer->getActiveSpan(), 'three is still active');
        $scope_three->close();
        $this->assertSame($scope_one->getSpan(), $this->tracer->getActiveSpan(), 'one is now active');
    }

    public function test_get_scope_manager(): void
    {
        $this->assertInstanceOf(ScopeManager::class, $this->tracer->getScopeManager());
    }

    public function test_log_exception(): void
    {
        $span = $this->tracer->startSpan('test');
        $span->log(['exception' => new \RuntimeException('kaboom')]);
        $span->finish();
        /** @var \OpenTelemetry\SDK\Trace\ImmutableSpan $s */
        $s = $this->storage[0];
        $this->assertCount(1, $s->getEvents());
        $event = $s->getEvents()[0];
        $this->assertSame('exception', $event->getName());
        $attributes = $event->getAttributes()->toArray();
        $this->assertSame('RuntimeException', $attributes['exception.type']);
        $this->assertSame('kaboom', $attributes['exception.message']);
        $this->assertNotNull($attributes['exception.stacktrace']);
    }

    public function test_log_exception_with_string(): void
    {
        $span = $this->tracer->startSpan('test');
        $span->log(['exception' => 'kaboom']);
        $span->finish();
        /** @var \OpenTelemetry\SDK\Trace\ImmutableSpan $s */
        $s = $this->storage[0];
        $attributes = $s->getEvents()[0]->getAttributes();
        $this->assertSame('kaboom', $attributes->get('exception.message'));
    }

    public function test_log_named_event(): void
    {
        $span = $this->tracer->startSpan('test');
        $span->log(['event' => 'foo', 'baz' => 'bat']);
        $span->finish();
        /** @var \OpenTelemetry\SDK\Trace\ImmutableSpan $s */
        $s = $this->storage[0];
        $event = $s->getEvents()[0];
        $this->assertSame('foo', $event->getName());
        $this->assertSame('bat', $event->getAttributes()->get('baz'));
    }

    /**
     * @dataProvider errorProvider
     */
    public function test_error_tag_is_mapped_to_span_status($value, string $expected): void
    {
        $span = $this->tracer->startSpan('test');
        $span->setTag('error', $value);
        $span->finish();
        /** @var \OpenTelemetry\SDK\Trace\ImmutableSpan $s */
        $s = $this->storage[0];
        $this->assertSame($expected, $s->getStatus()->getCode());
    }

    public static function errorProvider(): array
    {
        return [
            ['true', StatusCode::STATUS_ERROR],
            [true, StatusCode::STATUS_ERROR],
            ['false', StatusCode::STATUS_OK],
            [false, StatusCode::STATUS_OK],
            ['other', StatusCode::STATUS_UNSET],
        ];
    }
}
