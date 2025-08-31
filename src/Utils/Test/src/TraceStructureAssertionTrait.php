<?php

declare(strict_types=1);

namespace OpenTelemetry\TestUtils;

use ArrayObject;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\TestUtils\Fluent\TraceAssertion;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Constraint\Constraint;
use Traversable;

/**
 * Trait TraceStructureAssertionTrait
 *
 * Provides methods to assert the structure of OpenTelemetry traces.
 */
trait TraceStructureAssertionTrait
{
    /**
     * Creates a fluent interface for asserting trace structure.
     *
     * @param array|ArrayObject|Traversable $spans The spans to assess
     * @param bool $strict Whether to perform strict matching
     * @return TraceAssertion
     */
    public function assertTrace($spans, bool $strict = false): TraceAssertion
    {
        return new TraceAssertion($spans, $strict);
    }

    /**
     * Assesses if the given spans match the expected trace structure.
     *
     * @param array|ArrayObject|Traversable $spans The spans to assess (typically from InMemoryExporter)
     * @param array $expectedStructure The expected structure of the trace
     * @param bool $strict Whether to perform strict matching (all attributes must match)
     * @throws AssertionFailedError When the spans don't match the expected structure
     * @return void
     */
    public function assertTraceStructure($spans, array $expectedStructure, bool $strict = false): void
    {
        // Convert spans to array if needed
        $spansArray = $this->convertSpansToArray($spans);

        // Build a map of spans by ID
        $spanMap = $this->buildSpanMap($spansArray);

        // Build the actual trace structure
        $actualStructure = $this->buildTraceStructure($spanMap);

        // Compare the actual structure with the expected structure
        $this->compareTraceStructures($actualStructure, $expectedStructure, $strict);
    }

    /**
     * Converts spans to an array if they are in a different format and filters out non-span items.
     *
     * @psalm-suppress UnusedVariable
     * @param array|ArrayObject|Traversable $spans
     * @return array
     */
    private function convertSpansToArray($spans): array
    {
        $array = [];

        if (is_array($spans)) {
            $array = $spans;
        } elseif ($spans instanceof ArrayObject || $spans instanceof Traversable) {
            $array = iterator_to_array($spans);
        } else {
            /** @phpstan-ignore deadCode.unreachable */
            throw new \InvalidArgumentException('Spans must be an array, ArrayObject, or Traversable');
        }

        // Filter out non-span items
        return array_filter($array, function ($item) {
            return $item instanceof ImmutableSpan;
        });
    }

    /**
     * Builds a map of spans indexed by their span IDs.
     *
     * @param array $spans
     * @return array
     */
    private function buildSpanMap(array $spans): array
    {
        $spanMap = [];

        foreach ($spans as $span) {
            // All non-span items should have been filtered out in convertSpansToArray
            $spanMap[$span->getSpanId()] = [
                'span' => $span,
                'children' => [],
            ];
        }

        return $spanMap;
    }

    /**
     * Builds the hierarchical trace structure from the span map.
     *
     * @param array $spanMap
     * @return array
     */
    private function buildTraceStructure(array $spanMap): array
    {
        // First, establish parent-child relationships
        foreach ($spanMap as $spanId => $data) {
            $span = $data['span'];
            $parentSpanId = $span->getParentSpanId();

            // If the span has a parent and the parent is in our map
            if ($parentSpanId && isset($spanMap[$parentSpanId])) {
                $spanMap[$parentSpanId]['children'][] = $spanId;
            }
        }

        // Find root spans (spans without parents or with remote parents)
        $rootSpans = [];
        foreach ($spanMap as $spanId => $data) {
            $span = $data['span'];
            $parentSpanId = $span->getParentSpanId();

            // A span is a root span if it has no parent or its parent is not in our map
            if (!$parentSpanId || !isset($spanMap[$parentSpanId])) {
                $rootSpans[] = $spanId;
            }
        }

        // Build the trace structure starting from root spans
        $traceStructure = [];
        foreach ($rootSpans as $rootSpanId) {
            $traceStructure[] = $this->buildSpanStructure((string) $rootSpanId, $spanMap);
        }

        return $traceStructure;
    }

    /**
     * Recursively builds the structure for a span and its children.
     *
     * @param string $spanId
     * @param array $spanMap
     * @return array
     */
    private function buildSpanStructure(string $spanId, array $spanMap): array
    {
        $data = $spanMap[$spanId];
        $span = $data['span'];
        $childrenIds = $data['children'];

        $structure = [
            'name' => $span->getName(),
            'kind' => $span->getKind(),
            'spanId' => $span->getSpanId(),
            'traceId' => $span->getTraceId(),
            'parentSpanId' => $span->getParentSpanId(),
            'attributes' => $span->getAttributes()->toArray(),
            'status' => [
                'code' => $span->getStatus()->getCode(),
                'description' => $span->getStatus()->getDescription(),
            ],
            'events' => $this->extractEvents($span->getEvents()),
            'children' => [],
        ];

        // Recursively build children structures
        foreach ($childrenIds as $childId) {
            $structure['children'][] = $this->buildSpanStructure((string) $childId, $spanMap);
        }

        return $structure;
    }

    /**
     * Extracts event data from span events.
     *
     * @param array $events
     * @return array
     */
    private function extractEvents(array $events): array
    {
        $extractedEvents = [];

        foreach ($events as $event) {
            $extractedEvents[] = [
                'name' => $event->getName(),
                'attributes' => $event->getAttributes()->toArray(),
            ];
        }

        return $extractedEvents;
    }

    /**
     * Compares the actual trace structure with the expected structure.
     *
     * @param array $actualStructure
     * @param array $expectedStructure
     * @param bool $strict
     * @throws AssertionFailedError
     * @return void
     */
    private function compareTraceStructures(array $actualStructure, array $expectedStructure, bool $strict): void
    {
        try {
            // Check if the number of root spans matches
            Assert::assertCount(
                count($expectedStructure),
                $actualStructure,
                sprintf(
                    'Expected %d root spans, but found %d',
                    count($expectedStructure),
                    count($actualStructure)
                )
            );

            // For each expected root span, find a matching actual root span
            foreach ($expectedStructure as $expectedRootSpan) {
                $this->findMatchingSpan($expectedRootSpan, $actualStructure, $strict);
            }
        } catch (AssertionFailedError $e) {
            // Generate a detailed diff between expected and actual structures
            $diff = $this->generateTraceDiff($expectedStructure, $actualStructure);

            // Use Assert::fail() instead of throwing directly
            Assert::fail($e->getMessage() . "\n\n" . $diff);
        }
    }

    /**
     * Generates a detailed diff between expected and actual trace structures.
     *
     * @param array $expectedStructure The expected structure of the trace
     * @param array $actualStructure The actual structure of the trace
     * @return string The formatted diff
     */
    private function generateTraceDiff(array $expectedStructure, array $actualStructure): string
    {
        $output = "--- Expected Trace Structure\n";
        $output .= "+++ Actual Trace Structure\n";
        $output .= "@@ @@\n";

        // Generate the diff for the root level
        $output .= $this->generateArrayDiff($expectedStructure, $actualStructure);

        return $output;
    }

    /**
     * Recursively generates a diff between two arrays.
     *
     * @param array $expected The expected array
     * @param array $actual The actual array
     * @param int $depth The current depth for indentation
     * @return string The formatted diff
     */
    private function generateArrayDiff(array $expected, array $actual, int $depth = 0): string
    {
        $output = '';
        $indent = str_repeat('  ', $depth);

        // If arrays are indexed numerically, compare them as lists
        if ($this->isIndexedArray($expected) && $this->isIndexedArray($actual)) {
            $output .= $indent . "Array (\n";

            // Find the maximum index to iterate through
            $maxIndex = max(count($expected), count($actual)) - 1;

            for ($i = 0; $i <= $maxIndex; $i++) {
                if (isset($expected[$i]) && isset($actual[$i])) {
                    // Both arrays have this index, compare the values
                    if (is_array($expected[$i]) && is_array($actual[$i])) {
                        // Both values are arrays, recursively compare
                        $output .= $indent . "  [$i] => Array (\n";
                        $output .= $this->generateArrayDiff($expected[$i], $actual[$i], $depth + 2);
                        $output .= $indent . "  )\n";
                    } elseif ($expected[$i] === $actual[$i]) {
                        // Values are the same
                        $output .= $indent . "  [$i] => " . $this->formatValue($expected[$i]) . "\n";
                    } else {
                        // Values are different
                        $output .= $indent . "- [$i] => " . $this->formatValue($expected[$i]) . "\n";
                        $output .= $indent . "+ [$i] => " . $this->formatValue($actual[$i]) . "\n";
                    }
                } elseif (isset($expected[$i])) {
                    // Only in expected
                    $output .= $indent . "- [$i] => " . $this->formatValue($expected[$i]) . "\n";
                } else {
                    // Only in actual
                    $output .= $indent . "+ [$i] => " . $this->formatValue($actual[$i]) . "\n";
                }
            }

            $output .= $indent . ")\n";
        } else {
            // Compare as associative arrays
            $output .= $indent . "Array (\n";

            // Get all keys from both arrays
            $allKeys = array_unique(array_merge(array_keys($expected), array_keys($actual)));
            sort($allKeys);

            foreach ($allKeys as $key) {
                if (isset($expected[$key]) && isset($actual[$key])) {
                    // Both arrays have this key, compare the values
                    if (is_array($expected[$key]) && is_array($actual[$key])) {
                        // Both values are arrays, recursively compare
                        $output .= $indent . "  ['$key'] => Array (\n";
                        $output .= $this->generateArrayDiff($expected[$key], $actual[$key], $depth + 2);
                        $output .= $indent . "  )\n";
                    } elseif ($expected[$key] === $actual[$key]) {
                        // Values are the same
                        $output .= $indent . "  ['$key'] => " . $this->formatValue($expected[$key]) . "\n";
                    } else {
                        // Values are different
                        $output .= $indent . "- ['$key'] => " . $this->formatValue($expected[$key]) . "\n";
                        $output .= $indent . "+ ['$key'] => " . $this->formatValue($actual[$key]) . "\n";
                    }
                } elseif (isset($expected[$key])) {
                    // Only in expected
                    $output .= $indent . "- ['$key'] => " . $this->formatValue($expected[$key]) . "\n";
                } else {
                    // Only in actual
                    $output .= $indent . "+ ['$key'] => " . $this->formatValue($actual[$key]) . "\n";
                }
            }

            $output .= $indent . ")\n";
        }

        return $output;
    }

    /**
     * Checks if an array is indexed numerically (not associative).
     *
     * @param array $array The array to check
     * @return bool True if the array is indexed, false if it's associative
     */
    private function isIndexedArray(array $array): bool
    {
        if (empty($array)) {
            return true;
        }

        return array_keys($array) === range(0, count($array) - 1);
    }

    /**
     * Formats a value for display in the diff.
     *
     * @param mixed $value The value to format
     * @return string The formatted value
     */
    private function formatValue($value): string
    {
        if (is_string($value)) {
            return "'" . addslashes($value) . "'";
        } elseif (is_bool($value)) {
            return $value ? 'true' : 'false';
        } elseif (null === $value) {
            return 'null';
        } elseif (is_array($value)) {
            return 'Array(...)';
        } elseif (is_object($value)) {
            if ($value instanceof Constraint) {
                return 'Constraint(...)';
            }

            return 'Object(' . get_class($value) . ')';
        }

        return (string) $value;
    }

    /**
     * Finds a span in the actual structure that matches the expected span.
     *
     * @param array $expectedSpan
     * @param array $actualSpans
     * @param bool $strict
     * @throws AssertionFailedError
     * @return void
     */
    private function findMatchingSpan(array $expectedSpan, array $actualSpans, bool $strict): void
    {
        $expectedName = $expectedSpan['name'] ?? null;

        if ($expectedName === null) {
            throw new \InvalidArgumentException('Expected span must have a name');
        }

        // Check if the expected name is a constraint
        if ($this->isConstraint($expectedName)) {
            // Find spans that match the constraint
            $matchingSpans = [];
            foreach ($actualSpans as $actualSpan) {
                try {
                    Assert::assertThat(
                        $actualSpan['name'],
                        $expectedName,
                        'Span name does not match constraint'
                    );
                    $matchingSpans[] = $actualSpan;
                } catch (AssertionFailedError $e) {
                    // This span doesn't match the constraint, skip it
                    continue;
                }
            }
        } else {
            // Find spans with the exact matching name
            $matchingSpans = array_filter($actualSpans, function ($actualSpan) use ($expectedName) {
                return $actualSpan['name'] === $expectedName;
            });
        }

        Assert::assertNotEmpty(
            $matchingSpans,
            sprintf(
                'No span matching name "%s" found',
                $this->isConstraint($expectedName) ? 'constraint' : $expectedName
            )
        );

        // If multiple spans match, try to match based on other properties
        foreach ($matchingSpans as $actualSpan) {
            try {
                // For constraint-based names, we need to modify the expected span for comparison
                // since compareSpans expects exact name matching
                $spanToCompare = $expectedSpan;
                if ($this->isConstraint($expectedName)) {
                    $spanToCompare = $expectedSpan;
                    $spanToCompare['name'] = $actualSpan['name'];
                }

                $this->compareSpans($spanToCompare, $actualSpan, $strict);

                // If we get here, the spans match
                // Now check children if they exist
                if (isset($expectedSpan['children']) && !empty($expectedSpan['children'])) {
                    $this->compareChildren($expectedSpan['children'], $actualSpan['children'] ?? [], $strict);
                }

                // If we get here, the span and its children match
                return;
            } catch (AssertionFailedError $e) {
                // If the error is about an unexpected field in the status, rethrow it
                if (strpos($e->getMessage(), 'Unexpected field') !== false) {
                    throw $e;
                }

                // Otherwise, this span didn't match, try the next one
                continue;
            }
        }

        // If we get here, none of the spans matched
        Assert::fail(
            sprintf(
                'No matching span found for expected span "%s"',
                $this->isConstraint($expectedName) ? 'constraint' : $expectedName
            )
        );
    }

    /**
     * Compares an expected span with an actual span.
     *
     * @param array $expectedSpan
     * @param array $actualSpan
     * @param bool $strict
     * @throws AssertionFailedError
     * @return void
     */
    private function compareSpans(array $expectedSpan, array $actualSpan, bool $strict): void
    {
        // Compare name (already matched in findMatchingSpan, but double-check)
        Assert::assertSame(
            $expectedSpan['name'],
            $actualSpan['name'],
            'Span names do not match'
        );

        // Compare kind if specified
        if (isset($expectedSpan['kind'])) {
            $expectedKind = $expectedSpan['kind'];

            if ($this->isConstraint($expectedKind)) {
                Assert::assertThat(
                    $actualSpan['kind'],
                    $expectedKind,
                    sprintf('Span kind does not match constraint for span "%s"', $expectedSpan['name'])
                );
            } else {
                Assert::assertSame(
                    $expectedKind,
                    $actualSpan['kind'],
                    sprintf('Span kinds do not match for span "%s"', $expectedSpan['name'])
                );
            }
        } elseif ($strict && isset($actualSpan['kind']) && $actualSpan['kind'] !== 0) {
            // In strict mode, if kind is not specified in expected span but exists in actual span (and is not default),
            // the test should fail
            Assert::fail(sprintf('Actual span has kind %d but expected span does not specify kind for span "%s"', $actualSpan['kind'], $expectedSpan['name']));
        }

        // Compare attributes if specified
        if (isset($expectedSpan['attributes'])) {
            $this->compareAttributes(
                $expectedSpan['attributes'],
                $actualSpan['attributes'],
                $strict,
                $expectedSpan['name']
            );
        } elseif ($strict && isset($actualSpan['attributes']) && !empty($actualSpan['attributes'])) {
            // In strict mode, if attributes are not specified in expected span but exist in actual span,
            // the test should fail
            Assert::fail(sprintf('Actual span has attributes but expected span does not specify attributes for span "%s"', $expectedSpan['name']));
        }

        // Compare status if specified
        if (isset($expectedSpan['status'])) {
            $this->compareStatus(
                $expectedSpan['status'],
                $actualSpan['status'],
                $strict,
                $expectedSpan['name']
            );
        } elseif ($strict && isset($actualSpan['status']) &&
                 ($actualSpan['status']['code'] !== 0 || $actualSpan['status']['description'] !== '')) {
            // In strict mode, if status is not specified in expected span but exists in actual span (and is not default),
            // the test should fail
            Assert::fail(sprintf('Actual span has non-default status but expected span does not specify status for span "%s"', $expectedSpan['name']));
        }

        // Compare events if specified
        if (isset($expectedSpan['events'])) {
            $this->compareEvents(
                $expectedSpan['events'],
                $actualSpan['events'],
                $strict,
                $expectedSpan['name']
            );
        } elseif ($strict && isset($actualSpan['events']) && !empty($actualSpan['events'])) {
            // In strict mode, if events are not specified in expected span but exist in actual span,
            // the test should fail
            Assert::fail(sprintf('Actual span has events but expected span does not specify events for span "%s"', $expectedSpan['name']));
        }

        // In strict mode, check for children if not specified in expected span
        if ($strict && !isset($expectedSpan['children']) && isset($actualSpan['children']) && !empty($actualSpan['children'])) {
            Assert::fail(sprintf('Actual span has children but expected span does not specify children for span "%s"', $expectedSpan['name']));
        }
    }

    /**
     * Compares the children of an expected span with the children of an actual span.
     *
     * @param array $expectedChildren
     * @param array $actualChildren
     * @param bool $strict
     * @throws AssertionFailedError
     * @return void
     */
    private function compareChildren(array $expectedChildren, array $actualChildren, bool $strict): void
    {
        // Check if the number of children matches
        Assert::assertCount(
            count($expectedChildren),
            $actualChildren,
            sprintf(
                'Expected %d child spans, but found %d',
                count($expectedChildren),
                count($actualChildren)
            )
        );

        // For each expected child, find a matching actual child
        foreach ($expectedChildren as $expectedChild) {
            $this->findMatchingSpan($expectedChild, $actualChildren, $strict);
        }
    }

    /**
     * Checks if a value is a PHPUnit constraint object.
     *
     * @param mixed $value
     * @return bool
     */
    private function isConstraint($value): bool
    {
        return $value instanceof Constraint;
    }

    /**
     * Compares the attributes of an expected span with the attributes of an actual span.
     *
     * @param array $expectedAttributes
     * @param array $actualAttributes
     * @param bool $strict
     * @param string $spanName
     * @throws AssertionFailedError
     * @return void
     */
    private function compareAttributes(array $expectedAttributes, array $actualAttributes, bool $strict, string $spanName): void
    {
        // In strict mode, verify that the number of attributes matches exactly
        if ($strict) {
            Assert::assertCount(
                count($expectedAttributes),
                $actualAttributes,
                sprintf(
                    'Expected %d attributes, but found %d in span "%s"',
                    count($expectedAttributes),
                    count($actualAttributes),
                    $spanName
                )
            );
        }

        foreach ($expectedAttributes as $key => $expectedValue) {
            // Both in strict and non-strict mode, all expected attributes must be present
            Assert::assertArrayHasKey(
                $key,
                $actualAttributes,
                sprintf('Attribute "%s" not found in span "%s"', $key, $spanName)
            );

            // Get the actual value
            $actualValue = $actualAttributes[$key];

            // Check if the expected value is a constraint
            if ($this->isConstraint($expectedValue)) {
                // Use assertThat for constraint evaluation
                Assert::assertThat(
                    $actualValue,
                    $expectedValue,
                    sprintf('Attribute "%s" value does not match constraint in span "%s"', $key, $spanName)
                );
            } else {
                // Use regular assertEquals for direct comparison
                Assert::assertEquals(
                    $expectedValue,
                    $actualValue,
                    sprintf('Attribute "%s" value does not match in span "%s"', $key, $spanName)
                );
            }
        }
    }

    /**
     * Compares the status of an expected span with the status of an actual span.
     *
     * @param mixed $expectedStatus The expected status (multiple formats supported)
     * @param array $actualStatus The actual status
     * @param bool $strict Whether to perform strict matching
     * @param string $spanName The name of the span being compared
     * @throws AssertionFailedError
     * @return void
     */
    private function compareStatus($expectedStatus, array $actualStatus, bool $strict, string $spanName): void
    {
        // Case 1: Constraint directly on status code
        if ($this->isConstraint($expectedStatus)) {
            Assert::assertThat(
                $actualStatus['code'],
                $expectedStatus,
                sprintf('Status code does not match constraint for span "%s"', $spanName)
            );

            return;
        }

        // Case 2: Scalar value (direct status code comparison)
        if (is_scalar($expectedStatus)) {
            Assert::assertSame(
                $expectedStatus,
                $actualStatus['code'],
                sprintf('Status code does not match for span "%s"', $spanName)
            );

            return;
        }

        // Case 3: Simple indexed array [code, description]
        if (is_array($expectedStatus) && array_keys($expectedStatus) === [0, 1] && count($expectedStatus) === 2) {
            $expectedCode = $expectedStatus[0];
            $expectedDescription = $expectedStatus[1];

            // Compare code
            if ($this->isConstraint($expectedCode)) {
                Assert::assertThat(
                    $actualStatus['code'],
                    $expectedCode,
                    sprintf('Status code does not match constraint for span "%s"', $spanName)
                );
            } else {
                Assert::assertSame(
                    $expectedCode,
                    $actualStatus['code'],
                    sprintf('Status code does not match for span "%s"', $spanName)
                );
            }

            // Compare description
            if ($this->isConstraint($expectedDescription)) {
                Assert::assertThat(
                    $actualStatus['description'],
                    $expectedDescription,
                    sprintf('Status description does not match constraint for span "%s"', $spanName)
                );
            } else {
                Assert::assertSame(
                    $expectedDescription,
                    $actualStatus['description'],
                    sprintf('Status description does not match for span "%s"', $spanName)
                );
            }

            return;
        }

        // Case 4: Traditional associative array with keys
        if (is_array($expectedStatus)) {
            // In strict mode, verify that the expected status doesn't have unexpected fields
            if ($strict) {
                // Check for unexpected fields in expected status
                foreach (array_keys($expectedStatus) as $key) {
                    if (!in_array($key, ['code', 'description'])) {
                        Assert::fail(sprintf('Unexpected field "%s" in expected status for span "%s"', $key, $spanName));
                    }
                }

                // Check if code is specified in expected status
                if (!isset($expectedStatus['code']) && $actualStatus['code'] !== 0) {
                    Assert::fail(sprintf('Actual status has non-default code but expected status does not specify code for span "%s"', $spanName));
                }

                // Check if description is specified in expected status
                if (!isset($expectedStatus['description']) && $actualStatus['description'] !== '') {
                    Assert::fail(sprintf('Actual status has description but expected status does not specify description for span "%s"', $spanName));
                }
            }

            // Compare status code if specified
            if (isset($expectedStatus['code'])) {
                $expectedCode = $expectedStatus['code'];

                if ($this->isConstraint($expectedCode)) {
                    Assert::assertThat(
                        $actualStatus['code'],
                        $expectedCode,
                        sprintf('Status code does not match constraint for span "%s"', $spanName)
                    );
                } else {
                    Assert::assertSame(
                        $expectedCode,
                        $actualStatus['code'],
                        sprintf('Status code does not match for span "%s"', $spanName)
                    );
                }
            }

            // Compare status description if specified
            if (isset($expectedStatus['description'])) {
                $expectedDescription = $expectedStatus['description'];

                if ($this->isConstraint($expectedDescription)) {
                    Assert::assertThat(
                        $actualStatus['description'],
                        $expectedDescription,
                        sprintf('Status description does not match constraint for span "%s"', $spanName)
                    );
                } else {
                    Assert::assertSame(
                        $expectedDescription,
                        $actualStatus['description'],
                        sprintf('Status description does not match for span "%s"', $spanName)
                    );
                }
            }
        }
    }

    /**
     * Compares the events of an expected span with the events of an actual span.
     *
     * @param array $expectedEvents
     * @param array $actualEvents
     * @param bool $strict
     * @param string $spanName
     * @throws AssertionFailedError
     * @return void
     */
    private function compareEvents(array $expectedEvents, array $actualEvents, bool $strict, string $spanName): void
    {
        // In strict mode, the number of events must match
        if ($strict) {
            Assert::assertCount(
                count($expectedEvents),
                $actualEvents,
                sprintf(
                    'Expected %d events, but found %d in span "%s"',
                    count($expectedEvents),
                    count($actualEvents),
                    $spanName
                )
            );
        } else {
            // In non-strict mode, there must be at least as many actual events as expected
            Assert::assertGreaterThanOrEqual(
                count($expectedEvents),
                count($actualEvents),
                sprintf(
                    'Expected at least %d events, but found only %d in span "%s"',
                    count($expectedEvents),
                    count($actualEvents),
                    $spanName
                )
            );
        }

        // For each expected event, find a matching actual event
        foreach ($expectedEvents as $expectedEvent) {
            $this->findMatchingEvent($expectedEvent, $actualEvents, $strict, $spanName);
        }
    }

    /**
     * Finds an event in the actual events that matches the expected event.
     *
     * @param array $expectedEvent
     * @param array $actualEvents
     * @param bool $strict
     * @param string $spanName
     * @throws AssertionFailedError
     * @return void
     */
    private function findMatchingEvent(array $expectedEvent, array $actualEvents, bool $strict, string $spanName): void
    {
        $expectedName = $expectedEvent['name'] ?? null;

        if ($expectedName === null) {
            throw new \InvalidArgumentException('Expected event must have a name');
        }

        // Check if the expected name is a constraint
        if ($this->isConstraint($expectedName)) {
            // Find events that match the constraint
            $matchingEvents = [];
            foreach ($actualEvents as $actualEvent) {
                try {
                    Assert::assertThat(
                        $actualEvent['name'],
                        $expectedName,
                        'Event name does not match constraint'
                    );
                    $matchingEvents[] = $actualEvent;
                } catch (AssertionFailedError $e) {
                    // This event doesn't match the constraint, skip it
                    continue;
                }
            }
        } else {
            // Find events with the exact matching name
            $matchingEvents = array_filter($actualEvents, function ($actualEvent) use ($expectedName) {
                return $actualEvent['name'] === $expectedName;
            });
        }

        Assert::assertNotEmpty(
            $matchingEvents,
            sprintf(
                'No event matching name "%s" found in span "%s"',
                $this->isConstraint($expectedName) ? 'constraint' : $expectedName,
                $spanName
            )
        );

        // If multiple events match, try to match based on attributes
        foreach ($matchingEvents as $actualEvent) {
            try {
                // Compare attributes if specified
                if (isset($expectedEvent['attributes'])) {
                    $this->compareAttributes(
                        $expectedEvent['attributes'],
                        $actualEvent['attributes'],
                        $strict,
                        sprintf(
                            'Event "%s" in span "%s"',
                            $this->isConstraint($expectedName) ? $actualEvent['name'] : $expectedName,
                            $spanName
                        )
                    );
                }

                // If we get here, the event matches
                return;
            } catch (AssertionFailedError $e) {
                // This event didn't match, try the next one
                continue;
            }
        }

        // If we get here, none of the events matched
        Assert::fail(
            sprintf(
                'No matching event found for expected event "%s" in span "%s"',
                $this->isConstraint($expectedName) ? 'constraint' : $expectedName,
                $spanName
            )
        );
    }
}
