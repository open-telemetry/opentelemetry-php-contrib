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
use PHPUnit\Framework\TestCase;

/**
 * Tests for the TraceStructureAssertionTrait.
 */
class TraceStructureAssertionTraitTest extends TestCase
{
    use TraceStructureAssertionTrait;

    private ArrayObject $storage;
    private TracerProvider $tracerProvider;

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
     * Test asserting a simple trace structure with a single span.
     */
    public function test_assert_simple_trace_structure(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a single span
        $span = $tracer->spanBuilder('test-span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $span->setAttribute('attribute.one', 'value1');
        $span->setAttribute('attribute.two', 42);

        $span->end();

        // Define the expected structure
        $expectedStructure = [
            [
                'name' => 'test-span',
                'kind' => SpanKind::KIND_SERVER,
                'attributes' => [
                    'attribute.one' => 'value1',
                    'attribute.two' => 42,
                ],
            ],
        ];

        // Assert the trace structure
        $this->assertTraceStructure($this->storage, $expectedStructure);
    }

    /**
     * Test asserting a complex trace structure with parent-child relationships.
     */
    public function test_assert_complex_trace_structure(): void
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

        // Define the expected structure
        $expectedStructure = [
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
                        'events' => [
                            [
                                'name' => 'event.processed',
                                'attributes' => [
                                    'processed.id' => 'abc123',
                                ],
                            ],
                        ],
                    ],
                    [
                        'name' => 'another-child-span',
                        'kind' => SpanKind::KIND_CLIENT,
                        'status' => [
                            'code' => StatusCode::STATUS_ERROR,
                            'description' => 'Something went wrong',
                        ],
                    ],
                ],
            ],
        ];

        // Assert the trace structure
        $this->assertTraceStructure($this->storage, $expectedStructure);
    }

    /**
     * Test asserting a trace structure with strict matching.
     */
    public function test_assert_trace_structure_with_strict_matching(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a span with multiple attributes
        $span = $tracer->spanBuilder('test-span')
            ->startSpan();

        $span->setAttribute('attribute.one', 'value1');
        $span->setAttribute('attribute.two', 42);
        $span->setAttribute('attribute.three', true);

        $span->end();

        // Define the expected structure with only a subset of attributes
        $expectedStructure = [
            [
                'name' => 'test-span',
                'attributes' => [
                    'attribute.one' => 'value1',
                    'attribute.two' => 42,
                ],
            ],
        ];

        // Assert the trace structure with non-strict matching (should pass)
        $this->assertTraceStructure($this->storage, $expectedStructure, false);

        // Define the expected structure with all attributes
        $expectedStructureStrict = [
            [
                'name' => 'test-span',
                'attributes' => [
                    'attribute.one' => 'value1',
                    'attribute.two' => 42,
                    'attribute.three' => true,
                ],
            ],
        ];

        // Assert the trace structure with strict matching (should pass)
        $this->assertTraceStructure($this->storage, $expectedStructureStrict, true);
    }

    /**
     * Test asserting a trace structure with multiple root spans.
     */
    public function test_assert_trace_structure_with_multiple_root_spans(): void
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

        // Define the expected structure
        $expectedStructure = [
            [
                'name' => 'root-span-1',
                'kind' => SpanKind::KIND_SERVER,
            ],
            [
                'name' => 'root-span-2',
                'kind' => SpanKind::KIND_SERVER,
            ],
        ];

        // Assert the trace structure
        $this->assertTraceStructure($this->storage, $expectedStructure);
    }
}
