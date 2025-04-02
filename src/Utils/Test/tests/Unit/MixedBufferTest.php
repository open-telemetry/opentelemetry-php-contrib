<?php

declare(strict_types=1);

namespace OpenTelemetry\TestUtils\Tests\Unit;

use ArrayObject;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\TestUtils\TraceStructureAssertionTrait;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * Test to demonstrate that TraceStructureAssertionTrait only works with spans.
 */
class MixedBufferTest extends TestCase
{
    use TraceStructureAssertionTrait;

    private ArrayObject $sharedBuffer;
    private TracerProvider $tracerProvider;

    #[Override]
    protected function setUp(): void
    {
        // Create a shared buffer for both spans and logrecords
        $this->sharedBuffer = new ArrayObject();

        // Create a TracerProvider with an InMemoryExporter using the shared buffer
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->sharedBuffer)
            )
        );
    }

    /**
     * Test that demonstrates the trait now automatically filters out logrecords.
     */
    public function test_trait_automatically_filters_logrecords(): void
    {
        // Create a span
        $tracer = $this->tracerProvider->getTracer('test-tracer');
        $span = $tracer->spanBuilder('test-span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();
        $span->setAttribute('attribute.one', 'value1');
        $span->end();

        // Manually add a logrecord to the shared buffer
        $logRecord = new LogRecord('Test log message');
        $this->sharedBuffer->append($logRecord);

        // Define the expected structure
        $expectedStructure = [
            [
                'name' => 'test-span',
                'kind' => SpanKind::KIND_SERVER,
                'attributes' => [
                    'attribute.one' => 'value1',
                ],
            ],
        ];

        // This should now pass because the trait automatically filters out logrecords
        $this->assertTraceStructure($this->sharedBuffer, $expectedStructure);
    }

    /**
     * Test that demonstrates the manual solution to filter out logrecords still works.
     */
    public function test_manual_filtering_of_logrecords_still_works(): void
    {
        // Create a span
        $tracer = $this->tracerProvider->getTracer('test-tracer');
        $span = $tracer->spanBuilder('test-span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();
        $span->setAttribute('attribute.one', 'value1');
        $span->end();

        // Manually add a logrecord to the shared buffer
        $logRecord = new LogRecord('Test log message');
        $this->sharedBuffer->append($logRecord);

        // Define the expected structure
        $expectedStructure = [
            [
                'name' => 'test-span',
                'kind' => SpanKind::KIND_SERVER,
                'attributes' => [
                    'attribute.one' => 'value1',
                ],
            ],
        ];

        // Filter the buffer to only include spans
        $spansOnly = array_filter(iterator_to_array($this->sharedBuffer), function ($item) {
            return $item instanceof ImmutableSpan;
        });

        // This should pass because we've filtered out the logrecords
        $this->assertTraceStructure($spansOnly, $expectedStructure);
    }
}
