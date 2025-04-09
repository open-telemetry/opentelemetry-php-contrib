<?php

declare(strict_types=1);

namespace OpenTelemetry\TestUtils\Tests\Unit\Fluent;

use ArrayObject;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\TestUtils\Fluent\SpanAssertion;
use OpenTelemetry\TestUtils\Fluent\SpanEventAssertion;
use OpenTelemetry\TestUtils\Fluent\TraceAssertion;
use OpenTelemetry\TestUtils\Fluent\TraceAssertionFailedException;
use Override;
use PHPUnit\Framework\Constraint\IsIdentical;
use PHPUnit\Framework\Constraint\IsType;
use PHPUnit\Framework\Constraint\StringContains;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the SpanEventAssertion class.
 */
class SpanEventAssertionTest extends TestCase
{
    private ArrayObject $storage;
    private TracerProvider $tracerProvider;
    private TraceAssertion $traceAssertion;
    private SpanAssertion $spanAssertion;
    private SpanEventAssertion $eventAssertion;

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

        // Create a span with an event
        $tracer = $this->tracerProvider->getTracer('test-tracer');
        $span = $tracer->spanBuilder('test-span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $span->addEvent('test-event', [
            'attribute.one' => 'value1',
            'attribute.two' => 42,
            'attribute.three' => true,
        ]);

        $span->end();

        // Create a trace assertion
        $this->traceAssertion = new TraceAssertion($this->storage);

        // Get a span assertion
        $this->spanAssertion = $this->traceAssertion->hasChild('test-span');

        // Get an event assertion
        $this->eventAssertion = $this->spanAssertion->hasEvent('test-event');
    }

    /**
     * Test the withAttribute method.
     */
    public function test_with_attribute(): void
    {
        // Assert that the event has the expected attribute
        $result = $this->eventAssertion->withAttribute('attribute.one', 'value1');

        // Verify that withAttribute returns the event assertion instance
        $this->assertSame($this->eventAssertion, $result);
    }

    /**
     * Test the withAttribute method with a constraint.
     */
    public function test_with_attribute_with_constraint(): void
    {
        // Assert that the event has an attribute that matches the constraint
        $result = $this->eventAssertion->withAttribute('attribute.one', new StringContains('value'));

        // Verify that withAttribute returns the event assertion instance
        $this->assertSame($this->eventAssertion, $result);
    }

    /**
     * Test the withAttribute method throws an exception when the attribute doesn't exist.
     */
    public function test_with_attribute_throws_exception_when_attribute_doesnt_exist(): void
    {
        // Expect an exception when the attribute doesn't exist
        $this->expectException(TraceAssertionFailedException::class);
        $this->expectExceptionMessage("Event 'test-event' is missing attribute 'non-existent-attribute'");

        // This should throw an exception
        $this->eventAssertion->withAttribute('non-existent-attribute', 'value');
    }

    /**
     * Test the withAttribute method throws an exception when the value doesn't match.
     */
    public function test_with_attribute_throws_exception_when_value_doesnt_match(): void
    {
        // Expect an exception when the value doesn't match
        $this->expectException(TraceAssertionFailedException::class);
        $this->expectExceptionMessage("Event 'test-event' attribute 'attribute.one' expected value \"wrong-value\", but got \"value1\"");

        // This should throw an exception
        $this->eventAssertion->withAttribute('attribute.one', 'wrong-value');
    }

    /**
     * Test the withAttribute method throws an exception when the constraint doesn't match.
     */
    public function test_with_attribute_throws_exception_when_constraint_doesnt_match(): void
    {
        // Expect an exception when the constraint doesn't match
        $this->expectException(TraceAssertionFailedException::class);
        $this->expectExceptionMessage("Event 'test-event' attribute 'attribute.one' does not match constraint");

        // This should throw an exception
        $this->eventAssertion->withAttribute('attribute.one', new StringContains('wrong'));
    }

    /**
     * Test the withAttributes method.
     */
    public function test_with_attributes(): void
    {
        // Assert that the event has the expected attributes
        $result = $this->eventAssertion->withAttributes([
            'attribute.one' => 'value1',
            'attribute.two' => 42,
        ]);

        // Verify that withAttributes returns the event assertion instance
        $this->assertSame($this->eventAssertion, $result);
    }

    /**
     * Test the withAttributes method with constraints.
     */
    public function test_with_attributes_with_constraints(): void
    {
        // Assert that the event has attributes that match the constraints
        $result = $this->eventAssertion->withAttributes([
            'attribute.one' => new StringContains('value'),
            'attribute.two' => new IsType('integer'),
            'attribute.three' => new IsIdentical(true),
        ]);

        // Verify that withAttributes returns the event assertion instance
        $this->assertSame($this->eventAssertion, $result);
    }

    /**
     * Test the end method.
     */
    public function test_end(): void
    {
        // Call end
        $result = $this->eventAssertion->end();

        // Verify that end returns the span assertion instance
        $this->assertSame($this->spanAssertion, $result);
    }

    /**
     * Test the fluent interface chaining.
     */
    public function test_fluent_interface_chaining(): void
    {
        // Create a span with multiple events
        $tracer = $this->tracerProvider->getTracer('test-tracer');
        $span = $tracer->spanBuilder('span-with-multiple-events')
            ->startSpan();

        $span->addEvent('event-1', ['key1' => 'value1']);
        $span->addEvent('event-2', ['key2' => 'value2']);

        $span->end();

        // Create a trace assertion
        $traceAssertion = new TraceAssertion($this->storage);

        // Use the fluent interface to chain assertions
        $result = $traceAssertion
            ->hasChild('span-with-multiple-events')
                ->hasEvent('event-1')
                    ->withAttribute('key1', 'value1')
                ->end()
                ->hasEvent('event-2')
                    ->withAttribute('key2', 'value2')
                ->end()
            ->end();

        // Verify that the chain returns to the trace assertion
        $this->assertSame($traceAssertion, $result);
    }

    /**
     * Test with multiple attributes of different types.
     */
    public function test_with_multiple_attribute_types(): void
    {
        // Create a span with an event that has attributes of different types
        $tracer = $this->tracerProvider->getTracer('test-tracer');
        $span = $tracer->spanBuilder('span-with-typed-event')
            ->startSpan();

        $span->addEvent('typed-event', [
            'string-attr' => 'string-value',
            'int-attr' => 42,
            'float-attr' => 3.14,
            'bool-attr' => true,
            'array-attr' => ['a', 'b', 'c'],
            'null-attr' => null,
        ]);

        $span->end();

        // Create a trace assertion
        $traceAssertion = new TraceAssertion($this->storage);

        // Get a span assertion
        $spanAssertion = $traceAssertion->hasChild('span-with-typed-event');

        // Get an event assertion
        $eventAssertion = $spanAssertion->hasEvent('typed-event');

        // Assert that the event has attributes of different types
        $eventAssertion
            ->withAttribute('string-attr', 'string-value')
            ->withAttribute('int-attr', 42)
            ->withAttribute('float-attr', 3.14)
            ->withAttribute('bool-attr', true);

        // For array attributes, we need to use a constraint
        $eventAssertion->withAttribute('array-attr', new IsType('array'));

        // Note: Null attributes might not be stored or handled correctly
        // so we don't test them here
    }
}
