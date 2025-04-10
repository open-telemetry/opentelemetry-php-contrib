<?php

declare(strict_types=1);

namespace OpenTelemetry\TestUtils\Tests\Unit;

use ArrayObject;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\TestUtils\TraceStructureAssertionTrait;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * This test demonstrates how to create and work with multiple spans in OpenTelemetry.
 * It can be used as a reference for instrumenting applications with OpenTelemetry.
 */
class MultiSpanTest extends TestCase
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
     * Test creating multiple spans with parent-child relationships.
     */
    public function test_create_multiple_spans_with_parent_child_relationship(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a root span
        $rootSpan = $tracer->spanBuilder('root-span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        // Activate the root span to make it the current span
        $rootScope = $rootSpan->activate();

        try {
            // Create a child span (automatically becomes a child of the active span)
            $childSpan = $tracer->spanBuilder('child-span')
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->startSpan();

            // Add some attributes to the child span
            $childSpan->setAttribute('attribute.one', 'value1');
            $childSpan->setAttribute('attribute.two', 42);

            // Add an event to the child span
            $childSpan->addEvent('event.processed', [
                'processed.id' => 'abc123',
                'processed.timestamp' => time(),
            ]);

            // End the child span
            $childSpan->end();

            // Create another child span
            $anotherChildSpan = $tracer->spanBuilder('another-child-span')
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->startSpan();

            // Set error status on this span
            $anotherChildSpan->setStatus(StatusCode::STATUS_ERROR, 'Something went wrong');

            // End the second child span
            $anotherChildSpan->end();
        } finally {
            // End the root span
            $rootSpan->end();

            // Detach the root scope
            $rootScope->detach();
        }

        // Verify spans were created
        $this->assertCount(3, $this->storage);

        // Get the spans from storage (they are stored in the order they were ended)
        /** @var ImmutableSpan $firstChildSpan */
        $firstChildSpan = $this->storage[0];
        /** @var ImmutableSpan $secondChildSpan */
        $secondChildSpan = $this->storage[1];
        /** @var ImmutableSpan $exportedRootSpan */
        $exportedRootSpan = $this->storage[2];

        // Verify the root span
        $this->assertSame('root-span', $exportedRootSpan->getName());
        $this->assertSame(SpanKind::KIND_SERVER, $exportedRootSpan->getKind());

        // Verify the first child span
        $this->assertSame('child-span', $firstChildSpan->getName());
        $this->assertSame(SpanKind::KIND_INTERNAL, $firstChildSpan->getKind());
        $this->assertSame($exportedRootSpan->getSpanId(), $firstChildSpan->getParentSpanId());
        $this->assertSame($exportedRootSpan->getTraceId(), $firstChildSpan->getTraceId());

        // Verify attributes on the first child span
        $this->assertTrue($firstChildSpan->getAttributes()->has('attribute.one'));
        $this->assertSame('value1', $firstChildSpan->getAttributes()->get('attribute.one'));
        $this->assertTrue($firstChildSpan->getAttributes()->has('attribute.two'));
        $this->assertSame(42, $firstChildSpan->getAttributes()->get('attribute.two'));

        // Verify events on the first child span
        $this->assertCount(1, $firstChildSpan->getEvents());
        $event = $firstChildSpan->getEvents()[0];
        $this->assertSame('event.processed', $event->getName());
        $this->assertTrue($event->getAttributes()->has('processed.id'));
        $this->assertSame('abc123', $event->getAttributes()->get('processed.id'));

        // Verify the second child span
        $this->assertSame('another-child-span', $secondChildSpan->getName());
        $this->assertSame(SpanKind::KIND_CLIENT, $secondChildSpan->getKind());
        $this->assertSame($exportedRootSpan->getSpanId(), $secondChildSpan->getParentSpanId());
        $this->assertSame($exportedRootSpan->getTraceId(), $secondChildSpan->getTraceId());

        // Verify status on the second child span
        $this->assertSame(StatusCode::STATUS_ERROR, $secondChildSpan->getStatus()->getCode());
        $this->assertSame('Something went wrong', $secondChildSpan->getStatus()->getDescription());

        // Verify the trace structure using the TraceStructureAssertionTrait
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
                            'attribute.two' => 42,
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

        $this->assertTraceStructure($this->storage, $expectedStructure);
    }

    /**
     * Test creating multiple spans with explicit parent.
     */
    public function test_create_multiple_spans_with_explicit_parent(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a root span
        $rootSpan = $tracer->spanBuilder('root-span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        // Create a child span with explicit parent
        $childContext = Context::getCurrent()->withContextValue($rootSpan);
        $childSpan = $tracer->spanBuilder('child-span')
            ->setParent($childContext)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->startSpan();

        // Create a grandchild span with explicit parent
        $grandchildContext = Context::getCurrent()->withContextValue($childSpan);
        $grandchildSpan = $tracer->spanBuilder('grandchild-span')
            ->setParent($grandchildContext)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->startSpan();

        // End spans in reverse order
        $grandchildSpan->end();
        $childSpan->end();
        $rootSpan->end();

        // Verify spans were created
        $this->assertCount(3, $this->storage);

        // Get the spans from storage (they are stored in the order they were ended)
        /** @var ImmutableSpan $exportedGrandchildSpan */
        $exportedGrandchildSpan = $this->storage[0];
        /** @var ImmutableSpan $exportedChildSpan */
        $exportedChildSpan = $this->storage[1];
        /** @var ImmutableSpan $exportedRootSpan */
        $exportedRootSpan = $this->storage[2];

        // Verify the span hierarchy
        $this->assertSame('root-span', $exportedRootSpan->getName());
        $this->assertSame('child-span', $exportedChildSpan->getName());
        $this->assertSame('grandchild-span', $exportedGrandchildSpan->getName());

        // Verify parent-child relationships
        $this->assertSame($exportedRootSpan->getSpanId(), $exportedChildSpan->getParentSpanId());
        $this->assertSame($exportedChildSpan->getSpanId(), $exportedGrandchildSpan->getParentSpanId());

        // Verify all spans are part of the same trace
        $this->assertSame($exportedRootSpan->getTraceId(), $exportedChildSpan->getTraceId());
        $this->assertSame($exportedRootSpan->getTraceId(), $exportedGrandchildSpan->getTraceId());

        // Verify the trace structure using the TraceStructureAssertionTrait
        $expectedStructure = [
            [
                'name' => 'root-span',
                'kind' => SpanKind::KIND_SERVER,
                'children' => [
                    [
                        'name' => 'child-span',
                        'kind' => SpanKind::KIND_INTERNAL,
                        'children' => [
                            [
                                'name' => 'grandchild-span',
                                'kind' => SpanKind::KIND_INTERNAL,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertTraceStructure($this->storage, $expectedStructure);
    }

    /**
     * Test creating spans with different attributes and events.
     */
    public function test_create_spans_with_different_attributes_and_events(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a span with multiple attributes
        $span1 = $tracer->spanBuilder('span-with-attributes')
            ->startSpan();

        $span1->setAttribute('string.attribute', 'string-value');
        $span1->setAttribute('int.attribute', 42);
        $span1->setAttribute('bool.attribute', true);
        $span1->setAttribute('array.attribute', ['value1', 'value2', 'value3']);

        $span1->end();

        // Create a span with multiple events
        $span2 = $tracer->spanBuilder('span-with-events')
            ->startSpan();

        $span2->addEvent('event.one');
        $span2->addEvent('event.two', ['key1' => 'value1']);
        $span2->addEvent('event.three', [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ]);

        $span2->end();

        // Verify spans were created
        $this->assertCount(2, $this->storage);

        // Get the spans from storage
        /** @var ImmutableSpan $exportedSpan1 */
        $exportedSpan1 = $this->storage[0];
        /** @var ImmutableSpan $exportedSpan2 */
        $exportedSpan2 = $this->storage[1];

        // Verify attributes on span1
        $this->assertSame('span-with-attributes', $exportedSpan1->getName());
        $this->assertCount(4, $exportedSpan1->getAttributes());
        $this->assertSame('string-value', $exportedSpan1->getAttributes()->get('string.attribute'));
        $this->assertSame(42, $exportedSpan1->getAttributes()->get('int.attribute'));
        $this->assertTrue($exportedSpan1->getAttributes()->get('bool.attribute'));
        $this->assertSame(['value1', 'value2', 'value3'], $exportedSpan1->getAttributes()->get('array.attribute'));

        // Verify events on span2
        $this->assertSame('span-with-events', $exportedSpan2->getName());
        $this->assertCount(3, $exportedSpan2->getEvents());

        $events = $exportedSpan2->getEvents();
        $this->assertSame('event.one', $events[0]->getName());
        $this->assertCount(0, $events[0]->getAttributes());

        $this->assertSame('event.two', $events[1]->getName());
        $this->assertCount(1, $events[1]->getAttributes());
        $this->assertSame('value1', $events[1]->getAttributes()->get('key1'));

        $this->assertSame('event.three', $events[2]->getName());
        $this->assertCount(3, $events[2]->getAttributes());
        $this->assertSame('value1', $events[2]->getAttributes()->get('key1'));
        $this->assertSame('value2', $events[2]->getAttributes()->get('key2'));
        $this->assertSame('value3', $events[2]->getAttributes()->get('key3'));

        // Verify the trace structure using the TraceStructureAssertionTrait
        $expectedStructure = [
            [
                'name' => 'span-with-attributes',
                'attributes' => [
                    'string.attribute' => 'string-value',
                    'int.attribute' => 42,
                    'bool.attribute' => true,
                    'array.attribute' => ['value1', 'value2', 'value3'],
                ],
            ],
            [
                'name' => 'span-with-events',
                'events' => [
                    ['name' => 'event.one'],
                    [
                        'name' => 'event.two',
                        'attributes' => ['key1' => 'value1'],
                    ],
                    [
                        'name' => 'event.three',
                        'attributes' => [
                            'key1' => 'value1',
                            'key2' => 'value2',
                            'key3' => 'value3',
                        ],
                    ],
                ],
            ],
        ];

        $this->assertTraceStructure($this->storage, $expectedStructure);
    }
}
