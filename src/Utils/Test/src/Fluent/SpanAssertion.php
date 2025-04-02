<?php

declare(strict_types=1);

namespace OpenTelemetry\TestUtils\Fluent;

use OpenTelemetry\SDK\Trace\ImmutableSpan;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Fluent interface for asserting span properties.
 */
class SpanAssertion
{
    /** @var ImmutableSpan */
    private ImmutableSpan $span;

    /** @var TraceAssertion */
    private TraceAssertion $traceAssertion;

    /** @var SpanAssertion|null */
    private ?SpanAssertion $parentAssertion;

    /** @var array */
    private array $expectedStructure;

    /** @var array */
    private array $actualStructure;

    /**
     * @param ImmutableSpan $span The span to assert against
     * @param TraceAssertion $traceAssertion The parent trace assertion
     * @param SpanAssertion|null $parentAssertion The parent span assertion
     * @param array $expectedStructure The expected structure
     * @param array $actualStructure The actual structure
     */
    public function __construct(
        ImmutableSpan $span,
        TraceAssertion $traceAssertion,
        ?SpanAssertion $parentAssertion = null,
        array $expectedStructure = [],
        array $actualStructure = []
    ) {
        $this->span = $span;
        $this->traceAssertion = $traceAssertion;
        $this->parentAssertion = $parentAssertion;
        $this->expectedStructure = $expectedStructure;
        $this->actualStructure = $actualStructure;
    }

    /**
     * Assert that the span has the expected kind.
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @param int|Constraint $kind The expected kind
     * @throws TraceAssertionFailedException
     * @return self
     */
    public function withKind($kind): self
    {
        // Record the expectation
        $this->expectedStructure[] = [
            'type' => 'span_kind',
            'kind' => $kind instanceof Constraint ? 'constraint' : $kind,
        ];

        // Record the actual result
        $this->actualStructure[] = [
            'type' => 'span_kind',
            'kind' => $this->span->getKind(),
        ];

        // Perform the assertion
        if ($kind instanceof Constraint) {
            try {
                Assert::assertThat(
                    $this->span->getKind(),
                    $kind,
                    'Span kind does not match constraint'
                );
            } catch (AssertionFailedError $e) {
                throw new TraceAssertionFailedException(
                    sprintf(
                        "Span '%s' kind does not match constraint",
                        $this->span->getName()
                    ),
                    $this->expectedStructure,
                    $this->actualStructure
                );
            }
        } else {
            try {
                Assert::assertSame(
                    $kind,
                    $this->span->getKind(),
                    sprintf(
                        "Span '%s' expected kind %d, but got %d",
                        $this->span->getName(),
                        $kind,
                        $this->span->getKind()
                    )
                );
            } catch (AssertionFailedError $e) {
                throw new TraceAssertionFailedException(
                    $e->getMessage(),
                    $this->expectedStructure,
                    $this->actualStructure
                );
            }
        }

        return $this;
    }

    /**
     * Assert that the span has an attribute with the expected key and value.
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @param string $key The attribute key
     * @param mixed|Constraint $value The expected value
     * @throws TraceAssertionFailedException
     * @return self
     */
    public function withAttribute(string $key, $value): self
    {
        // Record the expectation
        $this->expectedStructure[] = [
            'type' => 'span_attribute',
            'key' => $key,
            'value' => $value instanceof Constraint ? 'constraint' : $value,
        ];

        // Check if the attribute exists
        if (!$this->span->getAttributes()->has($key)) {
            // Record the actual result
            $this->actualStructure[] = [
                'type' => 'missing_span_attribute',
                'key' => $key,
                'available_attributes' => array_keys($this->span->getAttributes()->toArray()),
            ];

            throw new TraceAssertionFailedException(
                sprintf(
                    "Span '%s' is missing attribute '%s'",
                    $this->span->getName(),
                    $key
                ),
                $this->expectedStructure,
                $this->actualStructure
            );
        }

        // Get the actual value
        $actualValue = $this->span->getAttributes()->get($key);

        // Record the actual result
        $this->actualStructure[] = [
            'type' => 'span_attribute',
            'key' => $key,
            'value' => $actualValue,
        ];

        // Perform the assertion
        if ($value instanceof Constraint) {
            try {
                Assert::assertThat(
                    $actualValue,
                    $value,
                    sprintf(
                        "Span '%s' attribute '%s' does not match constraint",
                        $this->span->getName(),
                        $key
                    )
                );
            } catch (AssertionFailedError $e) {
                throw new TraceAssertionFailedException(
                    $e->getMessage(),
                    $this->expectedStructure,
                    $this->actualStructure
                );
            }
        } else {
            try {
                Assert::assertEquals(
                    $value,
                    $actualValue,
                    sprintf(
                        "Span '%s' attribute '%s' expected value %s, but got %s",
                        $this->span->getName(),
                        $key,
                        $this->formatValue($value),
                        $this->formatValue($actualValue)
                    )
                );
            } catch (AssertionFailedError $e) {
                throw new TraceAssertionFailedException(
                    $e->getMessage(),
                    $this->expectedStructure,
                    $this->actualStructure
                );
            }
        }

        return $this;
    }

    /**
     * Assert that the span has the expected attributes.
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @param array $attributes The expected attributes
     * @throws TraceAssertionFailedException
     * @return self
     */
    public function withAttributes(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->withAttribute($key, $value);
        }

        return $this;
    }

    /**
     * Assert that the span has the expected status.
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @param string|Constraint $code The expected status code
     * @param string|Constraint|null $description The expected status description
     * @throws TraceAssertionFailedException
     * @return self
     */
    public function withStatus($code, $description = null): self
    {
        // Record the expectation
        $expectation = [
            'type' => 'span_status',
            'code' => $code instanceof Constraint ? 'constraint' : $code,
        ];

        if ($description !== null) {
            $expectation['description'] = $description instanceof Constraint ? 'constraint' : $description;
        }

        $this->expectedStructure[] = $expectation;

        // Get the actual status
        $actualCode = $this->span->getStatus()->getCode();
        $actualDescription = $this->span->getStatus()->getDescription();

        // Record the actual result
        $this->actualStructure[] = [
            'type' => 'span_status',
            'code' => $actualCode,
            'description' => $actualDescription,
        ];

        // Perform the assertion for code
        if ($code instanceof Constraint) {
            try {
                Assert::assertThat(
                    $actualCode,
                    $code,
                    sprintf(
                        "Span '%s' status code does not match constraint",
                        $this->span->getName()
                    )
                );
            } catch (AssertionFailedError $e) {
                throw new TraceAssertionFailedException(
                    $e->getMessage(),
                    $this->expectedStructure,
                    $this->actualStructure
                );
            }
        } else {
            try {
                Assert::assertSame(
                    $code,
                    $actualCode,
                    sprintf(
                        "Span '%s' expected status code %d, but got %d",
                        $this->span->getName(),
                        $code,
                        $actualCode
                    )
                );
            } catch (AssertionFailedError $e) {
                throw new TraceAssertionFailedException(
                    $e->getMessage(),
                    $this->expectedStructure,
                    $this->actualStructure
                );
            }
        }

        // Perform the assertion for description if provided
        if ($description !== null) {
            if ($description instanceof Constraint) {
                try {
                    Assert::assertThat(
                        $actualDescription,
                        $description,
                        sprintf(
                            "Span '%s' status description does not match constraint",
                            $this->span->getName()
                        )
                    );
                } catch (AssertionFailedError $e) {
                    throw new TraceAssertionFailedException(
                        $e->getMessage(),
                        $this->expectedStructure,
                        $this->actualStructure
                    );
                }
            } else {
                try {
                    Assert::assertSame(
                        $description,
                        $actualDescription,
                        sprintf(
                            "Span '%s' expected status description '%s', but got '%s'",
                            $this->span->getName(),
                            $description,
                            $actualDescription
                        )
                    );
                } catch (AssertionFailedError $e) {
                    throw new TraceAssertionFailedException(
                        $e->getMessage(),
                        $this->expectedStructure,
                        $this->actualStructure
                    );
                }
            }
        }

        return $this;
    }

    /**
     * Assert that the span has an event with the expected name.
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @param string|Constraint $name The expected event name
     * @throws TraceAssertionFailedException
     * @return SpanEventAssertion
     */
    public function hasEvent($name): SpanEventAssertion
    {
        // Record the expectation
        $this->expectedStructure[] = [
            'type' => 'span_event',
            'name' => $name instanceof Constraint ? 'constraint' : $name,
        ];

        // Find the matching event
        $events = $this->span->getEvents();
        $matchingEvent = null;

        if ($name instanceof Constraint) {
            foreach ($events as $event) {
                try {
                    Assert::assertThat(
                        $event->getName(),
                        $name,
                        'Event name does not match constraint'
                    );
                    $matchingEvent = $event;

                    break;
                } catch (AssertionFailedError $e) {
                    // This event doesn't match the constraint, skip it
                    continue;
                }
            }
        } else {
            foreach ($events as $event) {
                if ($event->getName() === $name) {
                    $matchingEvent = $event;

                    break;
                }
            }
        }

        if (!$matchingEvent) {
            // Record the actual result
            $this->actualStructure[] = [
                'type' => 'missing_span_event',
                'expected_name' => $name instanceof Constraint ? 'constraint' : $name,
                'available_events' => array_map(function ($event) {
                    return $event->getName();
                }, $events),
            ];

            throw new TraceAssertionFailedException(
                sprintf(
                    "Span '%s' has no event matching name '%s'",
                    $this->span->getName(),
                    $name instanceof Constraint ? 'constraint' : $name
                ),
                $this->expectedStructure,
                $this->actualStructure
            );
        }

        // Record the actual result
        $this->actualStructure[] = [
            'type' => 'span_event',
            'name' => $matchingEvent->getName(),
            'event' => $matchingEvent,
        ];

        return new SpanEventAssertion($matchingEvent, $this, $this->expectedStructure, $this->actualStructure);
    }

    /**
     * Assert that the span has a child span with the expected name.
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @param string|Constraint $name The expected child span name
     * @throws TraceAssertionFailedException
     * @return SpanAssertion
     */
    public function hasChild($name): SpanAssertion
    {
        // Find child spans
        $childSpans = [];
        $spanMap = $this->buildSpanMap($this->traceAssertion->getSpans());

        // Get the children of this span
        $childrenIds = $spanMap[$this->span->getSpanId()]['children'] ?? [];
        foreach ($childrenIds as $childId) {
            $childSpans[] = $spanMap[$childId]['span'];
        }

        // Record the expectation
        $this->expectedStructure[] = [
            'type' => 'child_span',
            'name' => $name instanceof Constraint ? 'constraint' : $name,
        ];

        // Find the matching child span
        $matchingSpan = null;

        if ($name instanceof Constraint) {
            foreach ($childSpans as $childSpan) {
                try {
                    Assert::assertThat(
                        $childSpan->getName(),
                        $name,
                        'Child span name does not match constraint'
                    );
                    $matchingSpan = $childSpan;

                    break;
                } catch (AssertionFailedError $e) {
                    // This span doesn't match the constraint, skip it
                    continue;
                }
            }
        } else {
            foreach ($childSpans as $childSpan) {
                if ($childSpan->getName() === $name) {
                    $matchingSpan = $childSpan;

                    break;
                }
            }
        }

        if (!$matchingSpan) {
            // Record the actual result
            $this->actualStructure[] = [
                'type' => 'missing_child_span',
                'expected_name' => $name instanceof Constraint ? 'constraint' : $name,
                'available_children' => array_map(function ($span) {
                    return $span->getName();
                }, $childSpans),
            ];

            throw new TraceAssertionFailedException(
                sprintf(
                    "Span '%s' has no child span matching name '%s'",
                    $this->span->getName(),
                    $name instanceof Constraint ? 'constraint' : $name
                ),
                $this->expectedStructure,
                $this->actualStructure
            );
        }

        // Record the actual result
        $this->actualStructure[] = [
            'type' => 'child_span',
            'name' => $matchingSpan->getName(),
            'span' => $matchingSpan,
        ];

        return new SpanAssertion($matchingSpan, $this->traceAssertion, $this, $this->expectedStructure, $this->actualStructure);
    }

    /**
     * Assert that the span has a root span with the given name.
     * This is a convenience method that delegates to the trace assertion.
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @param string|Constraint $name The expected name of the root span
     * @throws TraceAssertionFailedException
     * @return SpanAssertion
     */
    public function hasRootSpan($name): SpanAssertion
    {
        return $this->traceAssertion->hasRootSpan($name);
    }

    /**
     * Assert that the span has the expected number of children.
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @param int $count The expected number of children
     * @throws TraceAssertionFailedException
     * @return self
     */
    public function hasChildren(int $count): self
    {
        // Find child spans
        $childSpans = [];
        $spanMap = $this->buildSpanMap($this->traceAssertion->getSpans());

        // Get the children of this span
        $childrenIds = $spanMap[$this->span->getSpanId()]['children'] ?? [];
        foreach ($childrenIds as $childId) {
            $childSpans[] = $spanMap[$childId]['span'];
        }

        // Record the expectation
        $this->expectedStructure[] = [
            'type' => 'child_span_count',
            'count' => $count,
        ];

        // Record the actual result
        $this->actualStructure[] = [
            'type' => 'child_span_count',
            'count' => count($childSpans),
            'children' => array_map(function ($span) {
                return $span->getName();
            }, $childSpans),
        ];

        try {
            Assert::assertCount(
                $count,
                $childSpans,
                sprintf(
                    "Span '%s' expected %d child spans, but found %d",
                    $this->span->getName(),
                    $count,
                    count($childSpans)
                )
            );
        } catch (AssertionFailedError $e) {
            throw new TraceAssertionFailedException(
                $e->getMessage(),
                $this->expectedStructure,
                $this->actualStructure
            );
        }

        return $this;
    }

    /**
     * Return to the parent assertion.
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @return SpanAssertion|TraceAssertion
     */
    public function end()
    {
        return $this->parentAssertion ?? $this->traceAssertion;
    }

    /**
     * Format a value for display.
     *
     * @param mixed $value The value to format
     * @return string
     */
    private function formatValue($value): string
    {
        if (is_string($value)) {
            return "\"$value\"";
        } elseif (is_bool($value)) {
            return $value ? 'true' : 'false';
        } elseif (null === $value) {
            return 'null';
        } elseif (is_array($value)) {
            $json = json_encode($value);

            return $json === false ? '[unable to encode]' : $json;
        }

        return (string) $value;

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

        // Establish parent-child relationships
        foreach ($spanMap as $spanId => $data) {
            $span = $data['span'];
            $parentSpanId = $span->getParentSpanId();

            // If the span has a parent and the parent is in our map
            if ($parentSpanId && isset($spanMap[$parentSpanId])) {
                $spanMap[$parentSpanId]['children'][] = $spanId;
            }
        }

        return $spanMap;
    }
}
