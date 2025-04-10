<?php

declare(strict_types=1);

namespace OpenTelemetry\TestUtils\Tests\Unit;

use ArrayObject;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\TestUtils\Fluent\TraceAssertionFailedException;
use OpenTelemetry\TestUtils\TraceStructureAssertionTrait;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the TraceAssertionFailedException class.
 */
class TraceAssertionFailedExceptionTest extends TestCase
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
     * Test that the TraceAssertionFailedException provides a visual diff when an assertion fails.
     */
    public function test_trace_assertion_failed_exception_provides_visual_diff(): void
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
            $childSpan->setAttribute('attribute.two', 42);

            $childSpan->end();
        } finally {
            // End the root span
            $rootSpan->end();

            // Detach the root scope
            $rootScope->detach();
        }

        // Intentionally create an assertion that will fail
        try {
            $this->assertTrace($this->storage)
                ->hasRootSpan('root-span')
                    ->withKind(SpanKind::KIND_SERVER)
                    ->hasChild('child-span')
                        ->withKind(SpanKind::KIND_INTERNAL)
                        // This attribute doesn't exist, so it will fail
                        ->withAttribute('attribute.three', 'value3')
                    ->end()
                ->end();

            // If we get here, the test failed
            $this->fail('Expected TraceAssertionFailedException was not thrown');
        } catch (TraceAssertionFailedException $e) {
            // Verify that the exception message contains the expected and actual structures
            $message = $e->getMessage();

            // Check that the message contains the diff markers
            $this->assertStringContainsString('--- Expected Trace Structure', $message);
            $this->assertStringContainsString('+++ Actual Trace Structure', $message);

            // Check for specific content in the diff
            $this->assertStringContainsString('attribute.three', $message);

            // Verify that the exception contains the expected and actual structures
            $this->assertNotEmpty($e->getExpectedStructure());
            $this->assertNotEmpty($e->getActualStructure());
        }
    }

    /**
     * Test that the TraceAssertionFailedException provides a visual diff when a child span is missing.
     */
    public function test_trace_assertion_failed_exception_provides_visual_diff_for_missing_child(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a root span
        $rootSpan = $tracer->spanBuilder('root-span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $rootSpan->end();

        // Intentionally create an assertion that will fail due to a missing child span
        try {
            $this->assertTrace($this->storage)
                ->hasRootSpan('root-span')
                    ->withKind(SpanKind::KIND_SERVER)
                    // This child span doesn't exist, so it will fail
                    ->hasChild('non-existent-child')
                ->end();

            // If we get here, the test failed
            $this->fail('Expected TraceAssertionFailedException was not thrown');
        } catch (TraceAssertionFailedException $e) {
            // Verify that the exception message contains the expected and actual structures
            $message = $e->getMessage();

            // Check that the message contains the diff markers
            $this->assertStringContainsString('--- Expected Trace Structure', $message);
            $this->assertStringContainsString('+++ Actual Trace Structure', $message);

            // Check for specific content in the diff
            $this->assertStringContainsString('non-existent-child', $message);

            // Verify that the exception contains the expected and actual structures
            $this->assertNotEmpty($e->getExpectedStructure());
            $this->assertNotEmpty($e->getActualStructure());
        }
    }

    /**
     * Test that the TraceAssertionFailedException provides a visual diff when a span event is missing.
     * @psalm-suppress UnusedMethodCall
     */
    public function test_trace_assertion_failed_exception_provides_visual_diff_for_missing_event(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a root span
        $rootSpan = $tracer->spanBuilder('root-span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        // Add an event to the root span
        $rootSpan->addEvent('event.one');

        $rootSpan->end();

        // Intentionally create an assertion that will fail due to a missing event
        try {
            $this->assertTrace($this->storage)
                ->hasRootSpan('root-span')
                    ->withKind(SpanKind::KIND_SERVER)
                    // This event doesn't exist, so it will fail
                    ->hasEvent('non-existent-event')
                ->end();

            // If we get here, the test failed
            $this->fail('Expected TraceAssertionFailedException was not thrown');
        } catch (TraceAssertionFailedException $e) {
            // Verify that the exception message contains the expected and actual structures
            $message = $e->getMessage();

            // Check that the message contains the diff markers
            $this->assertStringContainsString('--- Expected Trace Structure', $message);
            $this->assertStringContainsString('+++ Actual Trace Structure', $message);

            // Check for specific content in the diff
            $this->assertStringContainsString('non-existent-event', $message);

            // Verify that the exception contains the expected and actual structures
            $this->assertNotEmpty($e->getExpectedStructure());
            $this->assertNotEmpty($e->getActualStructure());
        }
    }

    /**
     * Test that the TraceAssertionFailedException provides a visual diff when a span kind is incorrect.
     */
    public function test_trace_assertion_failed_exception_provides_visual_diff_for_incorrect_kind(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a root span
        $rootSpan = $tracer->spanBuilder('root-span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $rootSpan->end();

        // Intentionally create an assertion that will fail due to an incorrect kind
        try {
            $this->assertTrace($this->storage)
                ->hasRootSpan('root-span')
                    // This kind is incorrect, so it will fail
                    ->withKind(SpanKind::KIND_CLIENT)
                ->end();

            // If we get here, the test failed
            $this->fail('Expected TraceAssertionFailedException was not thrown');
        } catch (TraceAssertionFailedException $e) {
            // Verify that the exception message contains the expected and actual structures
            $message = $e->getMessage();

            // Check that the message contains the diff markers
            $this->assertStringContainsString('--- Expected Trace Structure', $message);
            $this->assertStringContainsString('+++ Actual Trace Structure', $message);

            // Check for specific content in the diff
            $this->assertStringContainsString('1', $message); // KIND_CLIENT = 1
            $this->assertStringContainsString('2', $message); // KIND_SERVER = 2

            // Verify that the exception contains the expected and actual structures
            $this->assertNotEmpty($e->getExpectedStructure());
            $this->assertNotEmpty($e->getActualStructure());
        }
    }
}
