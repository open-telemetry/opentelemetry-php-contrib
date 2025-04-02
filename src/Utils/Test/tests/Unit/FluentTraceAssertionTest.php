<?php

declare(strict_types=1);

namespace OpenTelemetry\TestUtils\Tests\Unit;

use ArrayObject;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\TestUtils\TraceStructureAssertionTrait;
use Override;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\Constraint\IsIdentical;
use PHPUnit\Framework\Constraint\IsType;
use PHPUnit\Framework\Constraint\RegularExpression;
use PHPUnit\Framework\Constraint\StringContains;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the fluent trace assertion interface.
 */
class FluentTraceAssertionTest extends TestCase
{
    use TraceStructureAssertionTrait;

    private ArrayObject $storage;
    private TracerProvider $tracerProvider;

    #[Override]
    public function setUp(): void
    {
        // Create a storage for the exported spans
        $this->storage = new ArrayObject();

        // Create a TracerProvider with an InMemoryExporter
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage)
            )
        );
    }

    /**
     * Test asserting a simple trace structure with a single span using the fluent interface.
     */
    public function test_assert_simple_trace_structure_with_fluent_interface(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a single span
        $span = $tracer->spanBuilder('test-span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $span->setAttribute('attribute.one', 'value1');
        $span->setAttribute('attribute.two', 42);

        $span->end();

        // Assert the trace structure using the fluent interface
        $this->assertTrace($this->storage)
            ->hasRootSpan('test-span')
                ->withKind(SpanKind::KIND_SERVER)
                ->withAttribute('attribute.one', 'value1')
                ->withAttribute('attribute.two', 42)
            ->end();
    }

    /**
     * Test asserting a complex trace structure with parent-child relationships using the fluent interface.
     */
    public function test_assert_complex_trace_structure_with_fluent_interface(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a root span
        $rootSpan = $tracer->spanBuilder('root-span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        // Activate the root span
        $rootScope = $rootSpan->activate();

        try {
            // Create a child span
            $childSpan = $tracer->spanBuilder('child-span')
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->startSpan();

            $childSpan->setAttribute('attribute.one', 'value1');
            $childSpan->addEvent('event.processed', [
                'processed.id' => 'abc123',
            ]);

            $childSpan->end();

            // Create another child span
            $anotherChildSpan = $tracer->spanBuilder('another-child-span')
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->startSpan();

            $anotherChildSpan->setStatus(StatusCode::STATUS_ERROR, 'Something went wrong');

            $anotherChildSpan->end();
        } finally {
            // End the root span
            $rootSpan->end();

            // Detach the root scope
            $rootScope->detach();
        }

        // Assert the trace structure using the fluent interface
        $this->assertTrace($this->storage)
            ->hasRootSpan('root-span')
                ->withKind(SpanKind::KIND_SERVER)
                ->hasChild('child-span')
                    ->withKind(SpanKind::KIND_INTERNAL)
                    ->withAttribute('attribute.one', 'value1')
                    ->hasEvent('event.processed')
                        ->withAttribute('processed.id', 'abc123')
                    ->end()
                ->end()
                ->hasChild('another-child-span')
                    ->withKind(SpanKind::KIND_CLIENT)
                    ->withStatus(StatusCode::STATUS_ERROR, 'Something went wrong')
                ->end()
            ->end();
    }

    /**
     * Test asserting a trace structure with strict matching using the fluent interface.
     */
    public function test_assert_trace_structure_with_strict_matching_using_fluent_interface(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a span with multiple attributes
        $span = $tracer->spanBuilder('test-span')
            ->startSpan();

        $span->setAttribute('attribute.one', 'value1');
        $span->setAttribute('attribute.two', 42);
        $span->setAttribute('attribute.three', true);

        $span->end();

        // Assert the trace structure with non-strict matching (should pass)
        $this->assertTrace($this->storage)
            ->hasRootSpan('test-span')
                ->withAttribute('attribute.one', 'value1')
                ->withAttribute('attribute.two', 42)
            ->end();

        // Assert the trace structure with strict matching (should pass)
        $this->assertTrace($this->storage, true)
            ->hasRootSpan('test-span')
                ->withAttributes([
                    'attribute.one' => 'value1',
                    'attribute.two' => 42,
                    'attribute.three' => true,
                ])
            ->end();
    }

    /**
     * Test asserting a trace structure with multiple root spans using the fluent interface.
     */
    public function test_assert_trace_structure_with_multiple_root_spans_using_fluent_interface(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create first root span
        $rootSpan1 = $tracer->spanBuilder('root-span-1')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $rootSpan1->end();

        // Create second root span
        $rootSpan2 = $tracer->spanBuilder('root-span-2')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $rootSpan2->end();

        // Assert the trace structure using the fluent interface
        $this->assertTrace($this->storage)
            ->hasRootSpans(2)
            ->hasRootSpan('root-span-1')
                ->withKind(SpanKind::KIND_SERVER)
            ->end()
            ->hasRootSpan('root-span-2')
                ->withKind(SpanKind::KIND_SERVER)
            ->end();
    }

    /**
     * Test asserting a trace structure using PHPUnit matchers with the fluent interface.
     */
    public function test_assert_trace_structure_with_phpunit_matchers_using_fluent_interface(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a root span
        $rootSpan = $tracer->spanBuilder('root-span-with-matchers')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $rootSpan->setAttribute('string.attribute', 'Hello, World!');
        $rootSpan->setAttribute('numeric.attribute', 42);
        $rootSpan->setAttribute('boolean.attribute', true);
        $rootSpan->setAttribute('array.attribute', ['a', 'b', 'c']);

        // Activate the root span
        $rootScope = $rootSpan->activate();

        try {
            // Create a child span
            $childSpan = $tracer->spanBuilder('child-span-123')
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->startSpan();

            $childSpan->setAttribute('timestamp', time());
            $childSpan->addEvent('process.start', [
                'process.id' => 12345,
                'process.name' => 'test-process',
            ]);

            $childSpan->end();

            // Create another child span
            $anotherChildSpan = $tracer->spanBuilder('error-span')
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->startSpan();

            $anotherChildSpan->setStatus(StatusCode::STATUS_ERROR, 'Error message');
            $anotherChildSpan->setAttribute('error.type', 'RuntimeException');

            $anotherChildSpan->end();
        } finally {
            // End the root span
            $rootSpan->end();

            // Detach the root scope
            $rootScope->detach();
        }

        // Assert the trace structure using the fluent interface with PHPUnit matchers
        $this->assertTrace($this->storage)
            ->hasRootSpan('root-span-with-matchers')
                ->withKind(new IsIdentical(SpanKind::KIND_SERVER))
                ->withAttribute('string.attribute', new StringContains('World'))
                ->withAttribute('numeric.attribute', new Callback(function ($value) {
                    /** @phpstan-ignore identical.alwaysFalse */
                    return $value > 40 || $value === 42;
                }))
                ->withAttribute('boolean.attribute', new IsType('boolean'))
                ->withAttribute('array.attribute', new Callback(function ($value) {
                    return is_array($value) && count($value) === 3 && in_array('b', $value);
                }))
                ->hasChild(new RegularExpression('/child-span-\d+/'))
                    ->withKind(SpanKind::KIND_INTERNAL)
                    ->withAttribute('timestamp', new IsType('integer'))
                    ->hasEvent('process.start')
                        ->withAttribute('process.id', new IsType('integer'))
                        ->withAttribute('process.name', new StringContains('process'))
                    ->end()
                ->end()
                ->hasChild(new StringContains('error'))
                    ->withKind(SpanKind::KIND_CLIENT)
                    ->withStatus(StatusCode::STATUS_ERROR, new StringContains('Error'))
                    ->withAttribute('error.type', new StringContains('Exception'))
                ->end()
            ->end();
    }

    /**
     * Test that both the fluent interface and the array-based interface can be used together.
     */
    public function test_both_interfaces_can_be_used_together(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a root span
        $rootSpan = $tracer->spanBuilder('root-span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        // Activate the root span
        $rootScope = $rootSpan->activate();

        try {
            // Create a child span
            $childSpan = $tracer->spanBuilder('child-span')
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->startSpan();

            $childSpan->setAttribute('attribute.one', 'value1');

            $childSpan->end();
        } finally {
            // End the root span
            $rootSpan->end();

            // Detach the root scope
            $rootScope->detach();
        }

        // Assert using the fluent interface
        $this->assertTrace($this->storage)
            ->hasRootSpan('root-span')
                ->withKind(SpanKind::KIND_SERVER)
                ->hasChild('child-span')
                    ->withKind(SpanKind::KIND_INTERNAL)
                    ->withAttribute('attribute.one', 'value1')
                ->end()
            ->end();

        // Assert using the array-based interface
        $this->assertTraceStructure($this->storage, [
            [
                'name' => 'root-span',
                'kind' => SpanKind::KIND_SERVER,
                'children' => [
                    [
                        'name' => 'child-span',
                        'kind' => SpanKind::KIND_INTERNAL,
                        'attributes' => [
                            'attribute.one' => 'value1',
                        ],
                    ],
                ],
            ],
        ]);
    }
}
