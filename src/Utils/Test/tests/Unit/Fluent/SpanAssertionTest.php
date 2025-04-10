<?php

declare(strict_types=1);

namespace OpenTelemetry\TestUtils\Tests\Unit\Fluent;

use ArrayObject;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\TestUtils\Fluent\SpanAssertion;
use OpenTelemetry\TestUtils\Fluent\TraceAssertion;
use OpenTelemetry\TestUtils\Fluent\TraceAssertionFailedException;
use Override;
use PHPUnit\Framework\Constraint\IsIdentical;
use PHPUnit\Framework\Constraint\IsType;
use PHPUnit\Framework\Constraint\StringContains;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the SpanAssertion class.
 */
class SpanAssertionTest extends TestCase
{
    private ArrayObject $storage;
    private TracerProvider $tracerProvider;
    private TraceAssertion $traceAssertion;
    private SpanAssertion $spanAssertion;

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

        // Create a span
        $tracer = $this->tracerProvider->getTracer('test-tracer');
        $span = $tracer->spanBuilder('test-span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $span->setAttribute('attribute.one', 'value1');
        $span->setAttribute('attribute.two', 42);
        $span->setAttribute('attribute.three', true);

        $span->end();

        // Create a trace assertion
        $this->traceAssertion = new TraceAssertion($this->storage);

        // Get a span assertion
        $this->spanAssertion = $this->traceAssertion->hasChild('test-span');
    }

    /**
     * Test the withKind method.
     */
    public function test_with_kind(): void
    {
        // Assert that the span has the expected kind
        $result = $this->spanAssertion->withKind(SpanKind::KIND_SERVER);

        // Verify that withKind returns the span assertion instance
        $this->assertSame($this->spanAssertion, $result);
    }

    /**
     * Test the withKind method with a constraint.
     */
    public function test_with_kind_with_constraint(): void
    {
        // Assert that the span has a kind that matches the constraint
        $result = $this->spanAssertion->withKind(new IsIdentical(SpanKind::KIND_SERVER));

        // Verify that withKind returns the span assertion instance
        $this->assertSame($this->spanAssertion, $result);
    }

    /**
     * Test the withKind method throws an exception when the kind doesn't match.
     */
    public function test_with_kind_throws_exception_when_kind_doesnt_match(): void
    {
        // Expect an exception when the kind doesn't match
        $this->expectException(TraceAssertionFailedException::class);
        $this->expectExceptionMessage("Span 'test-span' expected kind 1");

        // This should throw an exception
        $this->spanAssertion->withKind(SpanKind::KIND_CLIENT);
    }

    /**
     * Test the withKind method throws an exception when the constraint doesn't match.
     */
    public function test_with_kind_throws_exception_when_constraint_doesnt_match(): void
    {
        // Expect an exception when the constraint doesn't match
        $this->expectException(TraceAssertionFailedException::class);
        $this->expectExceptionMessage("Span 'test-span' kind does not match constraint");

        // This should throw an exception
        $this->spanAssertion->withKind(new IsIdentical(SpanKind::KIND_CLIENT));
    }

    /**
     * Test the withAttribute method.
     */
    public function test_with_attribute(): void
    {
        // Assert that the span has the expected attribute
        $result = $this->spanAssertion->withAttribute('attribute.one', 'value1');

        // Verify that withAttribute returns the span assertion instance
        $this->assertSame($this->spanAssertion, $result);
    }

    /**
     * Test the withAttribute method with a constraint.
     */
    public function test_with_attribute_with_constraint(): void
    {
        // Assert that the span has an attribute that matches the constraint
        $result = $this->spanAssertion->withAttribute('attribute.one', new StringContains('value'));

        // Verify that withAttribute returns the span assertion instance
        $this->assertSame($this->spanAssertion, $result);
    }

    /**
     * Test the withAttribute method throws an exception when the attribute doesn't exist.
     */
    public function test_with_attribute_throws_exception_when_attribute_doesnt_exist(): void
    {
        // Expect an exception when the attribute doesn't exist
        $this->expectException(TraceAssertionFailedException::class);
        $this->expectExceptionMessage("Span 'test-span' is missing attribute 'non-existent-attribute'");

        // This should throw an exception
        $this->spanAssertion->withAttribute('non-existent-attribute', 'value');
    }

    /**
     * Test the withAttribute method throws an exception when the value doesn't match.
     */
    public function test_with_attribute_throws_exception_when_value_doesnt_match(): void
    {
        // Expect an exception when the value doesn't match
        $this->expectException(TraceAssertionFailedException::class);
        $this->expectExceptionMessage("Span 'test-span' attribute 'attribute.one' expected value \"wrong-value\", but got \"value1\"");

        // This should throw an exception
        $this->spanAssertion->withAttribute('attribute.one', 'wrong-value');
    }

    /**
     * Test the withAttribute method throws an exception when the constraint doesn't match.
     */
    public function test_with_attribute_throws_exception_when_constraint_doesnt_match(): void
    {
        // Expect an exception when the constraint doesn't match
        $this->expectException(TraceAssertionFailedException::class);
        $this->expectExceptionMessage("Span 'test-span' attribute 'attribute.one' does not match constraint");

        // This should throw an exception
        $this->spanAssertion->withAttribute('attribute.one', new StringContains('wrong'));
    }

    /**
     * Test the withAttributes method.
     */
    public function test_with_attributes(): void
    {
        // Assert that the span has the expected attributes
        $result = $this->spanAssertion->withAttributes([
            'attribute.one' => 'value1',
            'attribute.two' => 42,
        ]);

        // Verify that withAttributes returns the span assertion instance
        $this->assertSame($this->spanAssertion, $result);
    }

    /**
     * Test the withAttributes method with constraints.
     */
    public function test_with_attributes_with_constraints(): void
    {
        // Assert that the span has attributes that match the constraints
        $result = $this->spanAssertion->withAttributes([
            'attribute.one' => new StringContains('value'),
            'attribute.two' => new IsType('integer'),
            'attribute.three' => new IsIdentical(true),
        ]);

        // Verify that withAttributes returns the span assertion instance
        $this->assertSame($this->spanAssertion, $result);
    }

    /**
     * Test the withStatus method.
     */
    public function test_with_status(): void
    {
        // Create a span with a status
        $tracer = $this->tracerProvider->getTracer('test-tracer');
        $span = $tracer->spanBuilder('span-with-status')
            ->startSpan();

        $span->setStatus(StatusCode::STATUS_ERROR, 'Error message');
        $span->end();

        // Create a trace assertion
        $traceAssertion = new TraceAssertion($this->storage);

        // Get a span assertion
        $spanAssertion = $traceAssertion->hasChild('span-with-status');

        // Assert that the span has the expected status
        $result = $spanAssertion->withStatus(StatusCode::STATUS_ERROR, 'Error message');

        // Verify that withStatus returns the span assertion instance
        $this->assertSame($spanAssertion, $result);
    }

    /**
     * Test the withStatus method with constraints.
     */
    public function test_with_status_with_constraints(): void
    {
        // Create a span with a status
        $tracer = $this->tracerProvider->getTracer('test-tracer');
        $span = $tracer->spanBuilder('span-with-status-constraints')
            ->startSpan();

        $span->setStatus(StatusCode::STATUS_ERROR, 'Error message');
        $span->end();

        // Create a trace assertion
        $traceAssertion = new TraceAssertion($this->storage);

        // Get a span assertion
        $spanAssertion = $traceAssertion->hasChild('span-with-status-constraints');

        // Assert that the span has a status that matches the constraints
        $result = $spanAssertion->withStatus(
            new IsIdentical(StatusCode::STATUS_ERROR),
            new StringContains('Error')
        );

        // Verify that withStatus returns the span assertion instance
        $this->assertSame($spanAssertion, $result);
    }

    /**
     * Test the hasEvent method.
     */
    public function test_has_event(): void
    {
        // Create a span with an event
        $tracer = $this->tracerProvider->getTracer('test-tracer');
        $span = $tracer->spanBuilder('span-with-event')
            ->startSpan();

        $span->addEvent('test-event');
        $span->end();

        // Create a trace assertion
        $traceAssertion = new TraceAssertion($this->storage);

        // Get a span assertion
        $spanAssertion = $traceAssertion->hasChild('span-with-event');

        // Assert that the span has the expected event
        $eventAssertion = $spanAssertion->hasEvent('test-event');

        // Verify that hasEvent returns a SpanEventAssertion instance
        $this->assertInstanceOf(\OpenTelemetry\TestUtils\Fluent\SpanEventAssertion::class, $eventAssertion);
    }

    /**
     * Test the hasEvent method with a constraint.
     */
    public function test_has_event_with_constraint(): void
    {
        // Create a span with an event
        $tracer = $this->tracerProvider->getTracer('test-tracer');
        $span = $tracer->spanBuilder('span-with-event-constraint')
            ->startSpan();

        $span->addEvent('test-event-with-suffix');
        $span->end();

        // Create a trace assertion
        $traceAssertion = new TraceAssertion($this->storage);

        // Get a span assertion
        $spanAssertion = $traceAssertion->hasChild('span-with-event-constraint');

        // Assert that the span has an event that matches the constraint
        $eventAssertion = $spanAssertion->hasEvent(new StringContains('event'));

        // Verify that hasEvent returns a SpanEventAssertion instance
        $this->assertInstanceOf(\OpenTelemetry\TestUtils\Fluent\SpanEventAssertion::class, $eventAssertion);
    }

    /**
     * Test the hasEvent method throws an exception when the event is not found.
     */
    public function test_has_event_throws_exception_when_event_not_found(): void
    {
        // Create a span with an event
        $tracer = $this->tracerProvider->getTracer('test-tracer');
        $span = $tracer->spanBuilder('span-with-event-not-found')
            ->startSpan();

        $span->addEvent('test-event');
        $span->end();

        // Create a trace assertion
        $traceAssertion = new TraceAssertion($this->storage);

        // Get a span assertion
        $spanAssertion = $traceAssertion->hasChild('span-with-event-not-found');

        // Expect an exception when the event is not found
        $this->expectException(TraceAssertionFailedException::class);
        $this->expectExceptionMessage("Span 'span-with-event-not-found' has no event matching name 'non-existent-event'");

        // This should throw an exception
        $spanAssertion->hasEvent('non-existent-event');
    }

    /**
     * Test the hasChild method.
     */
    public function test_has_child(): void
    {
        // Create a parent span
        $tracer = $this->tracerProvider->getTracer('test-tracer');
        $parentSpan = $tracer->spanBuilder('parent-span')
            ->startSpan();

        // Activate the parent span
        $parentScope = $parentSpan->activate();

        try {
            // Create a child span
            $childSpan = $tracer->spanBuilder('child-span')
                ->startSpan();

            $childSpan->end();
        } finally {
            // End the parent span
            $parentSpan->end();

            // Detach the parent scope
            $parentScope->detach();
        }

        // Create a trace assertion
        $traceAssertion = new TraceAssertion($this->storage);

        // Get a span assertion for the parent span
        $parentSpanAssertion = $traceAssertion->hasChild('parent-span');

        // Assert that the parent span has the expected child span
        $childSpanAssertion = $parentSpanAssertion->hasChild('child-span');

        // Verify that hasChild returns a SpanAssertion instance
        $this->assertInstanceOf(\OpenTelemetry\TestUtils\Fluent\SpanAssertion::class, $childSpanAssertion);
    }

    /**
     * Test the hasChild method with a constraint.
     */
    public function test_has_child_with_constraint(): void
    {
        // Create a parent span
        $tracer = $this->tracerProvider->getTracer('test-tracer');
        $parentSpan = $tracer->spanBuilder('parent-span-constraint')
            ->startSpan();

        // Activate the parent span
        $parentScope = $parentSpan->activate();

        try {
            // Create a child span
            $childSpan = $tracer->spanBuilder('child-span-with-suffix')
                ->startSpan();

            $childSpan->end();
        } finally {
            // End the parent span
            $parentSpan->end();

            // Detach the parent scope
            $parentScope->detach();
        }

        // Create a trace assertion
        $traceAssertion = new TraceAssertion($this->storage);

        // Get a span assertion for the parent span
        $parentSpanAssertion = $traceAssertion->hasChild('parent-span-constraint');

        // Assert that the parent span has a child span that matches the constraint
        $childSpanAssertion = $parentSpanAssertion->hasChild(new StringContains('child'));

        // Verify that hasChild returns a SpanAssertion instance
        $this->assertInstanceOf(\OpenTelemetry\TestUtils\Fluent\SpanAssertion::class, $childSpanAssertion);
    }

    /**
     * Test the hasChildren method.
     */
    public function test_has_children(): void
    {
        // Create a parent span
        $tracer = $this->tracerProvider->getTracer('test-tracer');
        $parentSpan = $tracer->spanBuilder('parent-span-children')
            ->startSpan();

        // Activate the parent span
        $parentScope = $parentSpan->activate();

        try {
            // Create two child spans
            $childSpan1 = $tracer->spanBuilder('child-span-1')
                ->startSpan();

            $childSpan1->end();

            $childSpan2 = $tracer->spanBuilder('child-span-2')
                ->startSpan();

            $childSpan2->end();
        } finally {
            // End the parent span
            $parentSpan->end();

            // Detach the parent scope
            $parentScope->detach();
        }

        // Create a trace assertion
        $traceAssertion = new TraceAssertion($this->storage);

        // Get a span assertion for the parent span
        $parentSpanAssertion = $traceAssertion->hasChild('parent-span-children');

        // Assert that the parent span has the expected number of children
        $result = $parentSpanAssertion->hasChildren(2);

        // Verify that hasChildren returns the span assertion instance
        $this->assertSame($parentSpanAssertion, $result);
    }

    /**
     * Test the hasChildren method throws an exception when the count doesn't match.
     */
    public function test_has_children_throws_exception_when_count_doesnt_match(): void
    {
        // Create a parent span
        $tracer = $this->tracerProvider->getTracer('test-tracer');
        $parentSpan = $tracer->spanBuilder('parent-span-children-count')
            ->startSpan();

        // Activate the parent span
        $parentScope = $parentSpan->activate();

        try {
            // Create one child span
            $childSpan = $tracer->spanBuilder('child-span')
                ->startSpan();

            $childSpan->end();
        } finally {
            // End the parent span
            $parentSpan->end();

            // Detach the parent scope
            $parentScope->detach();
        }

        // Create a trace assertion
        $traceAssertion = new TraceAssertion($this->storage);

        // Get a span assertion for the parent span
        $parentSpanAssertion = $traceAssertion->hasChild('parent-span-children-count');

        // Expect an exception when the count doesn't match
        $this->expectException(TraceAssertionFailedException::class);
        $this->expectExceptionMessage("Span 'parent-span-children-count' expected 2 child spans, but found 1");

        // This should throw an exception
        $parentSpanAssertion->hasChildren(2);
    }

    /**
     * Test the hasRootSpan method.
     */
    public function test_has_root_span(): void
    {
        // Create a root span
        $tracer = $this->tracerProvider->getTracer('test-tracer');
        $rootSpan = $tracer->spanBuilder('root-span-from-span-assertion')
            ->startSpan();

        $rootSpan->end();

        // Create a trace assertion
        $traceAssertion = new TraceAssertion($this->storage);

        // Get a span assertion for any span
        $spanAssertion = $traceAssertion->hasChild('test-span');

        // Assert that the trace has the expected root span
        $rootSpanAssertion = $spanAssertion->hasRootSpan('root-span-from-span-assertion');

        // Verify that hasRootSpan returns a SpanAssertion instance
        $this->assertInstanceOf(\OpenTelemetry\TestUtils\Fluent\SpanAssertion::class, $rootSpanAssertion);
    }

    /**
     * Test the end method.
     */
    public function test_end(): void
    {
        // Create a trace assertion
        $traceAssertion = new TraceAssertion($this->storage);

        // Get a span assertion
        $spanAssertion = $traceAssertion->hasChild('test-span');

        // Call end
        $result = $spanAssertion->end();

        // Verify that end returns the trace assertion instance
        $this->assertSame($traceAssertion, $result);
    }
}
