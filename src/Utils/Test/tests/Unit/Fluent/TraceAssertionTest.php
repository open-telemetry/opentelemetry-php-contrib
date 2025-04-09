<?php

declare(strict_types=1);

namespace OpenTelemetry\TestUtils\Tests\Unit\Fluent;

use ArrayObject;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\TestUtils\Fluent\TraceAssertion;
use OpenTelemetry\TestUtils\Fluent\TraceAssertionFailedException;
use Override;
use PHPUnit\Framework\Constraint\StringContains;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the TraceAssertion class.
 */
class TraceAssertionTest extends TestCase
{
    private ArrayObject $storage;
    private TracerProvider $tracerProvider;

    #[Override]
    protected function setUp(): void
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
     * Test the inStrictMode method.
     */
    public function test_in_strict_mode(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a span
        $span = $tracer->spanBuilder('test-span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $span->setAttribute('attribute.one', 'value1');
        $span->setAttribute('attribute.two', 42);

        $span->end();

        // Create a trace assertion
        $traceAssertion = new TraceAssertion($this->storage);

        // Enable strict mode
        $result = $traceAssertion->inStrictMode();

        // Verify that inStrictMode returns the trace assertion instance
        $this->assertSame($traceAssertion, $result);

        // Verify that strict mode is enabled
        $this->assertTrue($traceAssertion->isStrict());
    }

    /**
     * Test the hasChild method.
     */
    public function test_has_child(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a span
        $span = $tracer->spanBuilder('test-span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $span->end();

        // Create a trace assertion
        $traceAssertion = new TraceAssertion($this->storage);

        // Assert that the trace has a child span with the given name
        $spanAssertion = $traceAssertion->hasChild('test-span');

        // Verify that hasChild returns a SpanAssertion instance
        $this->assertInstanceOf(\OpenTelemetry\TestUtils\Fluent\SpanAssertion::class, $spanAssertion);
    }

    /**
     * Test the hasChild method with a constraint.
     */
    public function test_has_child_with_constraint(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a span
        $span = $tracer->spanBuilder('test-span-with-suffix')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $span->end();

        // Create a trace assertion
        $traceAssertion = new TraceAssertion($this->storage);

        // Assert that the trace has a child span with a name that contains 'span'
        $spanAssertion = $traceAssertion->hasChild(new StringContains('span'));

        // Verify that hasChild returns a SpanAssertion instance
        $this->assertInstanceOf(\OpenTelemetry\TestUtils\Fluent\SpanAssertion::class, $spanAssertion);
    }

    /**
     * Test the hasChild method throws an exception when the span is not found.
     */
    public function test_has_child_throws_exception_when_span_not_found(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a span
        $span = $tracer->spanBuilder('test-span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $span->end();

        // Create a trace assertion
        $traceAssertion = new TraceAssertion($this->storage);

        // Expect an exception when the span is not found
        $this->expectException(TraceAssertionFailedException::class);
        $this->expectExceptionMessage('No span matching name "non-existent-span" found');

        // This should throw an exception
        $traceAssertion->hasChild('non-existent-span');
    }

    /**
     * Test the end method.
     */
    public function test_end(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a span
        $span = $tracer->spanBuilder('test-span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $span->end();

        // Create a trace assertion
        $traceAssertion = new TraceAssertion($this->storage);

        // Call end
        $result = $traceAssertion->end();

        // Verify that end returns the trace assertion instance
        $this->assertSame($traceAssertion, $result);
    }

    /**
     * Test the hasRootSpans method.
     */
    public function test_has_root_spans(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create two root spans
        $rootSpan1 = $tracer->spanBuilder('root-span-1')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $rootSpan1->end();

        $rootSpan2 = $tracer->spanBuilder('root-span-2')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $rootSpan2->end();

        // Create a trace assertion
        $traceAssertion = new TraceAssertion($this->storage);

        // Assert that the trace has 2 root spans
        $result = $traceAssertion->hasRootSpans(2);

        // Verify that hasRootSpans returns the trace assertion instance
        $this->assertSame($traceAssertion, $result);
    }

    /**
     * Test the hasRootSpans method throws an exception when the count doesn't match.
     */
    public function test_has_root_spans_throws_exception_when_count_doesnt_match(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create one root span
        $rootSpan = $tracer->spanBuilder('root-span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $rootSpan->end();

        // Create a trace assertion
        $traceAssertion = new TraceAssertion($this->storage);

        // Expect an exception when the count doesn't match
        $this->expectException(TraceAssertionFailedException::class);
        $this->expectExceptionMessage('Expected 2 root spans, but found 1');

        // This should throw an exception
        $traceAssertion->hasRootSpans(2);
    }

    /**
     * Test the getSpans method.
     * @psalm-suppress RedundantCondition
     */
    public function test_get_spans(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a span
        $span = $tracer->spanBuilder('test-span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $span->end();

        // Create a trace assertion
        $traceAssertion = new TraceAssertion($this->storage);

        // Get the spans
        $spans = $traceAssertion->getSpans();

        // Verify that getSpans returns an array
        $this->assertIsArray($spans);
        $this->assertCount(1, $spans);
    }

    /**
     * Test the convertSpansToArray method with an ArrayObject.
     * @psalm-suppress RedundantCondition
     */
    public function test_convert_spans_to_array_with_array_object(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a span
        $span = $tracer->spanBuilder('test-span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $span->end();

        // Create a trace assertion with an ArrayObject
        $traceAssertion = new TraceAssertion($this->storage);

        // Get the spans
        $spans = $traceAssertion->getSpans();

        // Verify that the spans were converted to an array
        $this->assertIsArray($spans);
    }

    /**
     * Test the convertSpansToArray method with an array.
     * @psalm-suppress RedundantCondition
     */
    public function test_convert_spans_to_array_with_array(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a span
        $span = $tracer->spanBuilder('test-span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $span->end();

        // Convert the storage to an array
        $spansArray = iterator_to_array($this->storage);

        // Create a trace assertion with an array
        $traceAssertion = new TraceAssertion($spansArray);

        // Get the spans
        $spans = $traceAssertion->getSpans();

        // Verify that the spans are an array
        $this->assertIsArray($spans);
        $this->assertSame($spansArray, $spans);
    }

    /**
     * Test the convertSpansToArray method throws an exception with an invalid input.
     * @psalm-suppress InvalidArgument
     */
    public function test_convert_spans_to_array_throws_exception_with_invalid_input(): void
    {
        // Expect an exception when the input is invalid
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Spans must be an array, ArrayObject, or Traversable');

        // This should throw an exception
        /** @phpstan-ignore-next-line */
        new TraceAssertion('invalid');
    }
}
