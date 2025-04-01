<?php

declare(strict_types=1);

namespace OpenTelemetry\TestUtils;

use ArrayObject;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use Traversable;

/**
 * Trait TraceStructureAssertionTrait
 *
 * Provides methods to assert the structure of OpenTelemetry traces.
 */
trait TraceStructureAssertionTrait
{
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
     * Converts spans to an array if they are in a different format.
     *
     * @param array|ArrayObject|Traversable $spans
     * @return array
     */
    private function convertSpansToArray($spans): array
    {
        if (is_array($spans)) {
            return $spans;
        }

        if ($spans instanceof ArrayObject || $spans instanceof Traversable) {
            return iterator_to_array($spans);
        }

        throw new \InvalidArgumentException('Spans must be an array, ArrayObject, or Traversable');
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
            if (!$span instanceof ImmutableSpan) {
                throw new \InvalidArgumentException('Each span must be an instance of ImmutableSpan');
            }

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
            $traceStructure[] = $this->buildSpanStructure($rootSpanId, $spanMap);
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
            $structure['children'][] = $this->buildSpanStructure($childId, $spanMap);
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

        // Find a span with the matching name
        $matchingSpans = array_filter($actualSpans, function ($actualSpan) use ($expectedName) {
            return $actualSpan['name'] === $expectedName;
        });

        if (empty($matchingSpans)) {
            throw new AssertionFailedError(
                sprintf('No span with name "%s" found', $expectedName)
            );
        }

        // If multiple spans have the same name, try to match based on other properties
        foreach ($matchingSpans as $actualSpan) {
            try {
                $this->compareSpans($expectedSpan, $actualSpan, $strict);

                // If we get here, the spans match
                // Now check children if they exist
                if (isset($expectedSpan['children']) && !empty($expectedSpan['children'])) {
                    $this->compareChildren($expectedSpan['children'], $actualSpan['children'] ?? [], $strict);
                }

                // If we get here, the span and its children match
                return;
            } catch (AssertionFailedError $e) {
                // This span didn't match, try the next one
                continue;
            }
        }

        // If we get here, none of the spans matched
        throw new AssertionFailedError(
            sprintf('No matching span found for expected span "%s"', $expectedName)
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
            Assert::assertSame(
                $expectedSpan['kind'],
                $actualSpan['kind'],
                sprintf('Span kinds do not match for span "%s"', $expectedSpan['name'])
            );
        }

        // Compare attributes if specified
        if (isset($expectedSpan['attributes'])) {
            $this->compareAttributes(
                $expectedSpan['attributes'],
                $actualSpan['attributes'],
                $strict,
                $expectedSpan['name']
            );
        }

        // Compare status if specified
        if (isset($expectedSpan['status'])) {
            $this->compareStatus(
                $expectedSpan['status'],
                $actualSpan['status'],
                $expectedSpan['name']
            );
        }

        // Compare events if specified
        if (isset($expectedSpan['events'])) {
            $this->compareEvents(
                $expectedSpan['events'],
                $actualSpan['events'],
                $strict,
                $expectedSpan['name']
            );
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
        foreach ($expectedAttributes as $key => $expectedValue) {
            // In strict mode, all attributes must be present and match
            if ($strict) {
                Assert::assertArrayHasKey(
                    $key,
                    $actualAttributes,
                    sprintf('Attribute "%s" not found in span "%s"', $key, $spanName)
                );
            } elseif (!isset($actualAttributes[$key])) {
                // In non-strict mode, if the attribute is not present, skip it
                continue;
            }

            // Compare the attribute values
            Assert::assertEquals(
                $expectedValue,
                $actualAttributes[$key],
                sprintf('Attribute "%s" value does not match in span "%s"', $key, $spanName)
            );
        }
    }

    /**
     * Compares the status of an expected span with the status of an actual span.
     *
     * @param array $expectedStatus
     * @param array $actualStatus
     * @param string $spanName
     * @throws AssertionFailedError
     * @return void
     */
    private function compareStatus(array $expectedStatus, array $actualStatus, string $spanName): void
    {
        // Compare status code if specified
        if (isset($expectedStatus['code'])) {
            Assert::assertSame(
                $expectedStatus['code'],
                $actualStatus['code'],
                sprintf('Status code does not match for span "%s"', $spanName)
            );
        }

        // Compare status description if specified
        if (isset($expectedStatus['description'])) {
            Assert::assertSame(
                $expectedStatus['description'],
                $actualStatus['description'],
                sprintf('Status description does not match for span "%s"', $spanName)
            );
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
        } elseif (count($expectedEvents) > count($actualEvents)) {
            // In non-strict mode, there must be at least as many actual events as expected
            throw new AssertionFailedError(
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

        // Find an event with the matching name
        $matchingEvents = array_filter($actualEvents, function ($actualEvent) use ($expectedName) {
            return $actualEvent['name'] === $expectedName;
        });

        if (empty($matchingEvents)) {
            throw new AssertionFailedError(
                sprintf('No event with name "%s" found in span "%s"', $expectedName, $spanName)
            );
        }

        // If multiple events have the same name, try to match based on attributes
        foreach ($matchingEvents as $actualEvent) {
            try {
                // Compare attributes if specified
                if (isset($expectedEvent['attributes'])) {
                    $this->compareAttributes(
                        $expectedEvent['attributes'],
                        $actualEvent['attributes'],
                        $strict,
                        sprintf('Event "%s" in span "%s"', $expectedName, $spanName)
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
        throw new AssertionFailedError(
            sprintf('No matching event found for expected event "%s" in span "%s"', $expectedName, $spanName)
        );
    }
}
