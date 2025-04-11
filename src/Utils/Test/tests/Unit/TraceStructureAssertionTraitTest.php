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
 * Tests for the TraceStructureAssertionTrait.
 */
class TraceStructureAssertionTraitTest extends TestCase
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

        // Define the expected structure with all attributes and fields
        $expectedStructureStrict = [
            [
                'name' => 'test-span',
                'kind' => 0, // Default kind
                'attributes' => [
                    'attribute.one' => 'value1',
                    'attribute.two' => 42,
                    'attribute.three' => true,
                ],
                'status' => [
                    'code' => StatusCode::STATUS_UNSET, // Default status code
                    'description' => '', // Correct field name
                ],
            ],
        ];

        // Assert the trace structure with strict matching (should pass)
        $this->assertTraceStructure($this->storage, $expectedStructureStrict, true);
    }

    /**
     * Test that strict mode fails when status has an unexpected field.
     */
    public function test_assert_fails_with_unexpected_status_field_in_strict_mode(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a span with default status
        $span = $tracer->spanBuilder('test-span')
            ->startSpan();
        $span->end();

        // Define expected structure with a typo in the status field name
        $expectedStructure = [
            [
                'name' => 'test-span',
                'status' => [
                    'code' => StatusCode::STATUS_UNSET,
                    'descriptions' => '', // Typo: should be 'description'
                ],
            ],
        ];

        // Expect assertion to fail in strict mode due to unexpected field
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->expectExceptionMessage('Unexpected field "descriptions" in expected status');

        $this->assertTraceStructure($this->storage, $expectedStructure, true);
    }

    /**
     * Test that assertTraceStructure fails when expected attributes don't exist in the actual span,
     * even in non-strict mode.
     */
    public function test_assert_fails_with_nonexistent_attributes_in_nonstrict_mode(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a span with specific attributes
        $span = $tracer->spanBuilder('test-span')
            ->startSpan();

        $span->setAttribute('attribute.one', 'value1');
        $span->setAttribute('attribute.two', 42);

        $span->end();

        // Define expected structure with an attribute that doesn't exist in the actual span
        $expectedStructure = [
            [
                'name' => 'test-span',
                'attributes' => [
                    'attribute.one' => 'value1',
                    'nonexistent.attribute' => 'this-does-not-exist', // This attribute doesn't exist
                ],
            ],
        ];

        // Expect assertion to fail even in non-strict mode
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->expectExceptionMessage('No matching span found for expected span "test-span"');

        $this->assertTraceStructure($this->storage, $expectedStructure, false);
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

    /**
     * Test that assertTraceStructure fails when there are additional root spans.
     */
    public function test_assert_fails_with_additional_root_spans(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create two root spans
        $rootSpan1 = $tracer->spanBuilder('root-span-1')->startSpan();
        $rootSpan1->end();

        $rootSpan2 = $tracer->spanBuilder('root-span-2')->startSpan();
        $rootSpan2->end();

        // Define expected structure with only one root span
        $expectedStructure = [
            [
                'name' => 'root-span-1',
            ],
        ];

        // Expect assertion to fail
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->expectExceptionMessage('Expected 1 root spans, but found 2');

        $this->assertTraceStructure($this->storage, $expectedStructure);
    }

    /**
     * Test that assertTraceStructure fails when there are additional child spans.
     */
    public function test_assert_fails_with_additional_child_spans(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a root span
        $rootSpan = $tracer->spanBuilder('root-span')->startSpan();
        $rootScope = $rootSpan->activate();

        try {
            // Create two child spans
            $childSpan1 = $tracer->spanBuilder('child-span-1')->startSpan();
            $childSpan1->end();

            $childSpan2 = $tracer->spanBuilder('child-span-2')->startSpan();
            $childSpan2->end();
        } finally {
            $rootSpan->end();
            $rootScope->detach();
        }

        // Define expected structure with only one child span
        $expectedStructure = [
            [
                'name' => 'root-span',
                'children' => [
                    [
                        'name' => 'child-span-1',
                    ],
                ],
            ],
        ];

        // Expect assertion to fail
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        // We don't check the exact message as it might vary based on implementation details

        $this->assertTraceStructure($this->storage, $expectedStructure);
    }

    /**
     * Test that assertTraceStructure fails in strict mode when there are additional events.
     */
    public function test_assert_fails_with_additional_events_in_strict_mode(): void
    {
        // Create a new test setup to avoid interference from previous tests
        $storage = new ArrayObject();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($storage)
            )
        );

        $tracer = $tracerProvider->getTracer('test-tracer');

        // Create a span with multiple events
        $span = $tracer->spanBuilder('test-span')->startSpan();
        $span->addEvent('event-1');
        $span->addEvent('event-2');
        $span->end();

        // Define expected structure with only one event
        $expectedStructure = [
            [
                'name' => 'test-span',
                'events' => [
                    [
                        'name' => 'event-1',
                    ],
                ],
            ],
        ];

        // Assert passes in non-strict mode
        $this->assertTraceStructure($storage, $expectedStructure, false);

        // Create a new test setup for the strict mode test
        $strictStorage = new ArrayObject();
        $strictTracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($strictStorage)
            )
        );

        $strictTracer = $strictTracerProvider->getTracer('test-tracer');

        // Create the same span structure again
        $strictSpan = $strictTracer->spanBuilder('test-span')->startSpan();
        $strictSpan->addEvent('event-1');
        $strictSpan->addEvent('event-2');
        $strictSpan->end();

        // Expect assertion to fail in strict mode
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        // We don't check the exact message as it might vary based on implementation details

        $this->assertTraceStructure($strictStorage, $expectedStructure, true);
    }

    /**
     * Test that the diff output is generated correctly when an assertion fails.
     */
    public function test_trace_structure_diff_output(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a root span with specific attributes
        $rootSpan = $tracer->spanBuilder('root-span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $rootSpan->setAttribute('attribute.one', 'actual-value');
        $rootSpan->setAttribute('attribute.two', 42);
        $rootSpan->setAttribute('attribute.three', true);

        // Activate the root span
        $rootScope = $rootSpan->activate();

        try {
            // Create a child span
            $childSpan = $tracer->spanBuilder('child-span')
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->startSpan();

            $childSpan->setAttribute('child.attribute', 'child-value');
            $childSpan->end();
        } finally {
            $rootSpan->end();
            $rootScope->detach();
        }

        // Define an expected structure that doesn't match the actual structure
        $expectedStructure = [
            [
                'name' => 'root-span',
                'kind' => SpanKind::KIND_SERVER,
                'attributes' => [
                    'attribute.one' => 'expected-value', // Different value
                    'attribute.two' => 24, // Different value
                    // Missing attribute.three
                ],
                'children' => [
                    [
                        'name' => 'child-span',
                        'kind' => SpanKind::KIND_INTERNAL,
                        'attributes' => [
                            'child.attribute' => 'wrong-value', // Different value
                            'missing.attribute' => 'missing', // Extra attribute
                        ],
                    ],
                    [
                        'name' => 'missing-child-span', // Extra child span
                    ],
                ],
            ],
        ];

        try {
            // This should fail
            $this->assertTraceStructure($this->storage, $expectedStructure);
            $this->fail('Expected assertion to fail but it passed');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            // Verify that the error message contains the diff
            $errorMessage = $e->getMessage();

            // Check for diff markers
            $this->assertStringContainsString('--- Expected Trace Structure', $errorMessage);
            $this->assertStringContainsString('+++ Actual Trace Structure', $errorMessage);

            // Check for specific content in the diff
            $this->assertStringContainsString('expected-value', $errorMessage);
            $this->assertStringContainsString('actual-value', $errorMessage);
            $this->assertStringContainsString('24', $errorMessage);
            $this->assertStringContainsString('42', $errorMessage);
            $this->assertStringContainsString('attribute.three', $errorMessage);
            $this->assertStringContainsString('missing.attribute', $errorMessage);
            $this->assertStringContainsString('[1] => Array', $errorMessage); // This indicates the missing child span
        }
    }

    /**
     * Test that the diff output is generated correctly for multiple root spans.
     */
    public function test_trace_structure_diff_output_with_multiple_root_spans(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create first root span
        $rootSpan1 = $tracer->spanBuilder('root-span-1')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();
        $rootSpan1->setAttribute('attribute.one', 'actual-value-1');
        $rootSpan1->end();

        // Create second root span
        $rootSpan2 = $tracer->spanBuilder('root-span-2')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();
        $rootSpan2->setAttribute('attribute.two', 42);
        $rootSpan2->end();

        // Define an expected structure that doesn't match the actual structure
        $expectedStructure = [
            [
                'name' => 'root-span-1',
                'kind' => SpanKind::KIND_SERVER,
                'attributes' => [
                    'attribute.one' => 'expected-value-1', // Different value
                ],
            ],
            [
                'name' => 'root-span-2',
                'kind' => SpanKind::KIND_CLIENT,
                'attributes' => [
                    'attribute.two' => 24, // Different value
                    'attribute.three' => true, // Extra attribute
                ],
            ],
        ];

        try {
            // This should fail
            $this->assertTraceStructure($this->storage, $expectedStructure);
            $this->fail('Expected assertion to fail but it passed');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            // Verify that the error message contains the diff
            $errorMessage = $e->getMessage();

            // Check for diff markers
            $this->assertStringContainsString('--- Expected Trace Structure', $errorMessage);
            $this->assertStringContainsString('+++ Actual Trace Structure', $errorMessage);

            // Check for specific content in the diff for the first root span
            $this->assertStringContainsString('root-span-1', $errorMessage);
            $this->assertStringContainsString('expected-value-1', $errorMessage);
            $this->assertStringContainsString('actual-value-1', $errorMessage);

            // Check for specific content in the diff for the second root span
            $this->assertStringContainsString('root-span-2', $errorMessage);
            $this->assertStringContainsString('24', $errorMessage);
            $this->assertStringContainsString('42', $errorMessage);
            $this->assertStringContainsString('attribute.three', $errorMessage);
        }
    }

    /**
     * Test that the diff output is generated correctly when an expected root span is missing.
     */
    public function test_trace_structure_diff_output_with_missing_root_span(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create only one root span
        $rootSpan = $tracer->spanBuilder('root-span-1')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();
        $rootSpan->setAttribute('attribute.one', 'value1');
        $rootSpan->end();

        // Define an expected structure with two root spans
        $expectedStructure = [
            [
                'name' => 'root-span-1',
                'kind' => SpanKind::KIND_SERVER,
                'attributes' => [
                    'attribute.one' => 'value1',
                ],
            ],
            [
                'name' => 'root-span-2', // This span doesn't exist
                'kind' => SpanKind::KIND_CLIENT,
                'attributes' => [
                    'attribute.two' => 42,
                ],
            ],
        ];

        try {
            // This should fail
            $this->assertTraceStructure($this->storage, $expectedStructure);
            $this->fail('Expected assertion to fail but it passed');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            // Verify that the error message contains the diff
            $errorMessage = $e->getMessage();

            // Check for diff markers
            $this->assertStringContainsString('--- Expected Trace Structure', $errorMessage);
            $this->assertStringContainsString('+++ Actual Trace Structure', $errorMessage);

            // Check for specific content in the diff
            $this->assertStringContainsString('root-span-1', $errorMessage);

            // Check for the missing root span indicator in the diff
            $this->assertStringContainsString('[1] => Array', $errorMessage);

            // Check that the error message indicates the missing root span count
            $this->assertStringContainsString('Expected 2 root spans', $errorMessage);
            $this->assertStringContainsString('found 1', $errorMessage);
        }
    }

    /**
     * Test that the diff output is generated correctly when a nested child span is missing.
     */
    public function test_trace_structure_diff_output_with_missing_nested_span(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a root span
        $rootSpan = $tracer->spanBuilder('root-span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        // Activate the root span
        $rootScope = $rootSpan->activate();

        try {
            // Create only one child span
            $childSpan = $tracer->spanBuilder('child-span-1')
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->startSpan();
            $childSpan->setAttribute('attribute.one', 'value1');
            $childSpan->end();
        } finally {
            $rootSpan->end();
            $rootScope->detach();
        }

        // Define an expected structure with two child spans
        $expectedStructure = [
            [
                'name' => 'root-span',
                'kind' => SpanKind::KIND_SERVER,
                'children' => [
                    [
                        'name' => 'child-span-1',
                        'kind' => SpanKind::KIND_INTERNAL,
                        'attributes' => [
                            'attribute.one' => 'value1',
                        ],
                    ],
                    [
                        'name' => 'child-span-2', // This span doesn't exist
                        'kind' => SpanKind::KIND_CLIENT,
                        'attributes' => [
                            'attribute.two' => 42,
                        ],
                    ],
                ],
            ],
        ];

        try {
            // This should fail
            $this->assertTraceStructure($this->storage, $expectedStructure);
            $this->fail('Expected assertion to fail but it passed');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            // Verify that the error message contains the diff
            $errorMessage = $e->getMessage();

            // Check for diff markers
            $this->assertStringContainsString('--- Expected Trace Structure', $errorMessage);
            $this->assertStringContainsString('+++ Actual Trace Structure', $errorMessage);

            // Check for specific content in the diff
            $this->assertStringContainsString('root-span', $errorMessage);
            $this->assertStringContainsString('child-span-1', $errorMessage);

            // Check for the missing child span indicator in the diff
            $this->assertStringContainsString('[1] => Array', $errorMessage);

            // Check that the error message indicates a missing span
            $this->assertStringContainsString('No matching span found', $errorMessage);
        }
    }

    /**
     * Test that strict mode fails when actual span has extra attributes.
     */
    public function test_assert_fails_with_extra_attributes_in_strict_mode(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a span with multiple attributes
        $span = $tracer->spanBuilder('test-span')
            ->startSpan();

        $span->setAttribute('attribute.one', 'value1');
        $span->setAttribute('attribute.two', 42);
        $span->setAttribute('attribute.three', true);

        $span->end();

        // Define expected structure with only a subset of attributes
        $expectedStructure = [
            [
                'name' => 'test-span',
                'attributes' => [
                    'attribute.one' => 'value1',
                    'attribute.two' => 42,
                ],
            ],
        ];

        // Expect assertion to fail in strict mode
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/No matching span found for expected span "test-span"/');

        $this->assertTraceStructure($this->storage, $expectedStructure, true);
    }

    /**
     * Test that strict mode fails when actual span has a kind but expected doesn't.
     */
    public function test_assert_fails_with_extra_kind_in_strict_mode(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a span with a specific kind
        $span = $tracer->spanBuilder('test-span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $span->end();

        // Define expected structure without kind
        $expectedStructure = [
            [
                'name' => 'test-span',
            ],
        ];

        // Expect assertion to fail in strict mode
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/No matching span found for expected span "test-span"/');

        $this->assertTraceStructure($this->storage, $expectedStructure, true);
    }

    /**
     * Test that strict mode fails when actual span has events but expected doesn't.
     */
    public function test_assert_fails_with_extra_events_in_strict_mode(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a span with events
        $span = $tracer->spanBuilder('test-span')
            ->startSpan();

        $span->addEvent('event-1');
        $span->addEvent('event-2');

        $span->end();

        // Define expected structure without events
        $expectedStructure = [
            [
                'name' => 'test-span',
            ],
        ];

        // Expect assertion to fail in strict mode
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/No matching span found for expected span "test-span"/');

        $this->assertTraceStructure($this->storage, $expectedStructure, true);
    }

    /**
     * Test that strict mode fails when actual span has a non-default status but expected doesn't.
     */
    public function test_assert_fails_with_extra_status_in_strict_mode(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a span with a non-default status
        $span = $tracer->spanBuilder('test-span')
            ->startSpan();

        $span->setStatus(StatusCode::STATUS_ERROR, 'Something went wrong');

        $span->end();

        // Define expected structure without status
        $expectedStructure = [
            [
                'name' => 'test-span',
            ],
        ];

        // Expect assertion to fail in strict mode
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/No matching span found for expected span "test-span"/');

        $this->assertTraceStructure($this->storage, $expectedStructure, true);
    }

    /**
     * Test that strict mode fails when actual status has a non-default code but expected status doesn't specify a code.
     */
    public function test_assert_fails_when_actual_status_has_code_but_expected_doesnt_in_strict_mode(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a span with a non-default status code
        $span = $tracer->spanBuilder('test-span')
            ->startSpan();

        $span->setStatus(StatusCode::STATUS_ERROR, '');

        $span->end();

        // Define expected structure with status but without code
        $expectedStructure = [
            [
                'name' => 'test-span',
                'status' => [
                    'description' => '',
                ],
            ],
        ];

        // Expect assertion to fail in strict mode
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/No matching span found for expected span "test-span"/');

        $this->assertTraceStructure($this->storage, $expectedStructure, true);
    }

    /**
     * Test that strict mode fails when actual status has a description but expected status doesn't specify a description.
     */
    public function test_assert_fails_when_actual_status_has_description_but_expected_doesnt_in_strict_mode(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a span with a status description
        $span = $tracer->spanBuilder('test-span')
            ->startSpan();

        $span->setStatus(StatusCode::STATUS_ERROR, 'Something went wrong');

        $span->end();

        // Define expected structure with status but without description
        $expectedStructure = [
            [
                'name' => 'test-span',
                'status' => [
                    'code' => StatusCode::STATUS_ERROR,
                ],
            ],
        ];

        // Expect assertion to fail in strict mode
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/No matching span found for expected span "test-span"/');

        $this->assertTraceStructure($this->storage, $expectedStructure, true);
    }

    /**
     * Test that status code constraint checking fails with the correct error message.
     */
    public function test_assert_fails_with_status_code_constraint_error_message(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a span with error status
        $span = $tracer->spanBuilder('test-span')
            ->startSpan();

        $span->setStatus(StatusCode::STATUS_ERROR, 'Something went wrong');

        $span->end();

        // Create a constraint that will fail (expecting OK status)
        $constraint = new \PHPUnit\Framework\Constraint\IsIdentical(StatusCode::STATUS_OK);

        // Define expected structure with a constraint that will fail
        $expectedStructure = [
            [
                'name' => 'test-span',
                'status' => $constraint,
            ],
        ];

        try {
            $this->assertTraceStructure($this->storage, $expectedStructure);
            $this->fail('Expected assertion to fail but it passed');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            // Verify that the error message contains the expected text
            $errorMessage = $e->getMessage();
            $this->assertStringContainsString('No matching span found for expected span "test-span"', $errorMessage);
        }
    }

    /**
     * Test that strict mode fails when actual span has children but expected doesn't.
     */
    public function test_assert_fails_with_extra_children_in_strict_mode(): void
    {
        $tracer = $this->tracerProvider->getTracer('test-tracer');

        // Create a root span
        $rootSpan = $tracer->spanBuilder('root-span')
            ->startSpan();

        // Activate the root span
        $rootScope = $rootSpan->activate();

        try {
            // Create a child span
            $childSpan = $tracer->spanBuilder('child-span')
                ->startSpan();
            $childSpan->end();
        } finally {
            $rootSpan->end();
            $rootScope->detach();
        }

        // Define expected structure without children
        $expectedStructure = [
            [
                'name' => 'root-span',
            ],
        ];

        // Expect assertion to fail in strict mode
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/No matching span found for expected span "root-span"/');

        $this->assertTraceStructure($this->storage, $expectedStructure, true);
    }

    /**
     * Test asserting a trace structure using PHPUnit matchers.
     */
    public function test_assert_trace_structure_with_phpunit_matchers(): void
    {
        // Create a new test setup to avoid interference from previous tests
        $storage = new ArrayObject();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($storage)
            )
        );

        $tracer = $tracerProvider->getTracer('test-tracer');

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

        // First, test with a simple structure without matchers
        $simpleExpectedStructure = [
            [
                'name' => 'root-span-with-matchers',
            ],
        ];

        // This should pass
        $this->assertTraceStructure($storage, $simpleExpectedStructure);

        // Now test with PHPUnit matchers
        $expectedStructure = [
            [
                // Use exact string match for the name (not a matcher)
                'name' => 'root-span-with-matchers',
                'kind' => new IsIdentical(SpanKind::KIND_SERVER),
                'attributes' => [
                    'string.attribute' => new StringContains('World'),
                    'numeric.attribute' => new Callback(function ($value) {
                        /** @phpstan-ignore identical.alwaysFalse */
                        return $value > 40 || $value === 42;
                    }),
                    'boolean.attribute' => new IsType('boolean'),
                    'array.attribute' => new Callback(function ($value) {
                        return is_array($value) && count($value) === 3 && in_array('b', $value);
                    }),
                ],
                'children' => [
                    [
                        'name' => new RegularExpression('/child-span-\d+/'),
                        'kind' => SpanKind::KIND_INTERNAL,
                        'attributes' => [
                            'timestamp' => new IsType('integer'),
                        ],
                        'events' => [
                            [
                                'name' => 'process.start',
                                'attributes' => [
                                    'process.id' => new IsType('integer'),
                                    'process.name' => new StringContains('process'),
                                ],
                            ],
                        ],
                    ],
                    [
                        'name' => new StringContains('error'),
                        'kind' => SpanKind::KIND_CLIENT,
                        'status' => [
                            'code' => StatusCode::STATUS_ERROR,
                            'description' => new StringContains('Error'),
                        ],
                        'attributes' => [
                            'error.type' => new StringContains('Exception'),
                        ],
                    ],
                ],
            ],
        ];

        // Assert the trace structure with matchers
        $this->assertTraceStructure($storage, $expectedStructure);
    }

    /**
     * Test asserting a trace structure with status as a constraint.
     */
    public function test_assert_trace_structure_with_status_as_constraint(): void
    {
        // Create a new test setup
        $storage = new ArrayObject();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($storage)
            )
        );

        $tracer = $tracerProvider->getTracer('test-tracer');

        // Create a span with default status (UNSET)
        $span = $tracer->spanBuilder('default-status-span')
            ->startSpan();
        $span->end();

        // Test Format 1: Constraint directly on status code
        $expectedStructure = [
            [
                'name' => 'default-status-span',
                'status' => new IsIdentical(StatusCode::STATUS_UNSET),
            ],
        ];
        $this->assertTraceStructure($storage, $expectedStructure);
    }

    /**
     * Test asserting a trace structure with status as a scalar value.
     */
    public function test_assert_trace_structure_with_status_as_scalar(): void
    {
        // Create a new test setup
        $storage = new ArrayObject();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($storage)
            )
        );

        $tracer = $tracerProvider->getTracer('test-tracer');

        // Create a span with error status
        $span = $tracer->spanBuilder('error-status-span')
            ->startSpan();
        $span->setStatus(StatusCode::STATUS_ERROR, 'Something went wrong');
        $span->end();

        // Test Format 2: Scalar value (direct status code comparison)
        $expectedStructure = [
            [
                'name' => 'error-status-span',
                'status' => StatusCode::STATUS_ERROR,
            ],
        ];
        $this->assertTraceStructure($storage, $expectedStructure);
    }

    /**
     * Test asserting a trace structure with status as an indexed array.
     */
    public function test_assert_trace_structure_with_status_as_indexed_array(): void
    {
        // Create a new test setup
        $storage = new ArrayObject();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($storage)
            )
        );

        $tracer = $tracerProvider->getTracer('test-tracer');

        // Create a span with error status
        $span = $tracer->spanBuilder('error-status-span')
            ->startSpan();
        $span->setStatus(StatusCode::STATUS_ERROR, 'Something went wrong');
        $span->end();

        // Test Format 3: Simple indexed array [code, description]
        $expectedStructure = [
            [
                'name' => 'error-status-span',
                'status' => [StatusCode::STATUS_ERROR, 'Something went wrong'],
            ],
        ];
        $this->assertTraceStructure($storage, $expectedStructure);
    }

    /**
     * Test asserting a trace structure with status as an indexed array with constraint.
     */
    public function test_assert_trace_structure_with_status_as_indexed_array_with_constraint(): void
    {
        // Create a new test setup
        $storage = new ArrayObject();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($storage)
            )
        );

        $tracer = $tracerProvider->getTracer('test-tracer');

        // Create a span with error status
        $span = $tracer->spanBuilder('error-status-span')
            ->startSpan();
        $span->setStatus(StatusCode::STATUS_ERROR, 'Something went wrong');
        $span->end();

        // Test Format 3 with constraint for description
        $expectedStructure = [
            [
                'name' => 'error-status-span',
                'status' => [StatusCode::STATUS_ERROR, new StringContains('went wrong')],
            ],
        ];
        $this->assertTraceStructure($storage, $expectedStructure);
    }

    /**
     * Test asserting a trace structure with status as an associative array.
     */
    public function test_assert_trace_structure_with_status_as_associative_array(): void
    {
        // Create a new test setup
        $storage = new ArrayObject();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($storage)
            )
        );

        $tracer = $tracerProvider->getTracer('test-tracer');

        // Create a span with OK status
        $span = $tracer->spanBuilder('ok-status-span')
            ->startSpan();
        $span->setStatus(StatusCode::STATUS_OK, '');
        $span->end();

        // Test Format 4: Traditional associative array with keys
        $expectedStructure = [
            [
                'name' => 'ok-status-span',
                'status' => [
                    'code' => StatusCode::STATUS_OK,
                    'description' => '',
                ],
            ],
        ];
        $this->assertTraceStructure($storage, $expectedStructure);
    }

    /**
     * Test asserting a trace structure with multiple spans and different status formats.
     */
    public function test_assert_trace_structure_with_multiple_spans_and_status_formats(): void
    {
        // Create a new test setup
        $storage = new ArrayObject();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($storage)
            )
        );

        $tracer = $tracerProvider->getTracer('test-tracer');

        // Create spans with different status codes
        // Span 1: Default status (UNSET)
        $defaultSpan = $tracer->spanBuilder('default-status-span')
            ->startSpan();
        $defaultSpan->end();

        // Span 2: Error status with description
        $errorSpan = $tracer->spanBuilder('error-status-span')
            ->startSpan();
        $errorSpan->setStatus(StatusCode::STATUS_ERROR, 'Something went wrong');
        $errorSpan->end();

        // Span 3: OK status
        $okSpan = $tracer->spanBuilder('ok-status-span')
            ->startSpan();
        $okSpan->setStatus(StatusCode::STATUS_OK, '');
        $okSpan->end();

        // Test multiple spans with different status formats in one assertion
        $expectedStructure = [
            [
                'name' => 'default-status-span',
                'status' => new IsIdentical(StatusCode::STATUS_UNSET),
            ],
            [
                'name' => 'error-status-span',
                'status' => [StatusCode::STATUS_ERROR, new StringContains('went wrong')],
            ],
            [
                'name' => 'ok-status-span',
                'status' => StatusCode::STATUS_OK,
            ],
        ];
        $this->assertTraceStructure($storage, $expectedStructure);
    }
}
