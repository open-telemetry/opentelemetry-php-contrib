<?php

declare(strict_types=1);

namespace OpenTelemetry\TestUtils\Fluent;

use ArrayObject;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Constraint\Constraint;
use Traversable;

/**
 * Fluent interface for asserting trace structure.
 */
class TraceAssertion
{
    /** @var array */
    private array $spans;

    /** @var bool */
    private bool $strict;

    /** @var array */
    private array $expectedStructure = [];

    /** @var array */
    private array $actualStructure = [];

    /**
     * @param array|ArrayObject|Traversable $spans The spans to assess
     * @param bool $strict Whether to perform strict matching
     */
    public function __construct($spans, bool $strict = false)
    {
        $this->spans = $this->convertSpansToArray($spans);
        $this->strict = $strict;
    }

    /**
     * Enable strict mode for all assertions.
     *
     * @return self
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function inStrictMode(): self
    {
        $this->strict = true;

        return $this;
    }

    /**
     * Assert that the trace has a root span with the given name.
     *
     * @param string|Constraint $name The expected name of the root span
     * @throws TraceAssertionFailedException
     * @return SpanAssertion
     */
    public function hasRootSpan($name): SpanAssertion
    {
        // Find root spans (spans without parents or with remote parents)
        $rootSpans = [];
        $spanMap = $this->buildSpanMap($this->spans);

        foreach ($spanMap as $_ => $data) {
            $span = $data['span'];
            $parentSpanId = $span->getParentSpanId();

            // A span is a root span if it has no parent or its parent is not in our map
            if (!$parentSpanId || !isset($spanMap[$parentSpanId])) {
                $rootSpans[] = $span;
            }
        }

        // Record the expectation
        $this->expectedStructure[] = [
            'type' => 'root_span',
            'name' => $name instanceof Constraint ? 'constraint' : $name,
        ];

        // Find the matching root span
        $matchingSpan = null;

        if ($name instanceof Constraint) {
            foreach ($rootSpans as $rootSpan) {
                try {
                    Assert::assertThat(
                        $rootSpan->getName(),
                        $name,
                        'Root span name does not match constraint'
                    );
                    $matchingSpan = $rootSpan;

                    break;
                } catch (AssertionFailedError $e) {
                    // This span doesn't match the constraint, skip it
                    continue;
                }
            }
        } else {
            foreach ($rootSpans as $rootSpan) {
                if ($rootSpan->getName() === $name) {
                    $matchingSpan = $rootSpan;

                    break;
                }
            }
        }

        if (!$matchingSpan) {
            // Record the actual result
            $this->actualStructure[] = [
                'type' => 'missing_root_span',
                'expected_name' => $name instanceof Constraint ? 'constraint' : $name,
                'available_root_spans' => array_map(function ($span) {
                    return $span->getName();
                }, $rootSpans),
            ];

            throw new TraceAssertionFailedException(
                sprintf(
                    'No root span matching name "%s" found',
                    $name instanceof Constraint ? 'constraint' : $name
                ),
                $this->expectedStructure,
                $this->actualStructure
            );
        }

        // Record the actual result
        $this->actualStructure[] = [
            'type' => 'root_span',
            'name' => $matchingSpan->getName(),
            'span' => $matchingSpan,
        ];

        return new SpanAssertion($matchingSpan, $this, null, $this->expectedStructure, $this->actualStructure);
    }

    /**
     * Assert that the trace has a child span with the expected name.
     *
     * @param string|Constraint $name The expected child span name
     * @throws TraceAssertionFailedException
     * @return SpanAssertion
     */
    public function hasChild($name): SpanAssertion
    {
        // Find the matching span
        $matchingSpan = null;

        if ($name instanceof Constraint) {
            foreach ($this->spans as $span) {
                try {
                    Assert::assertThat(
                        $span->getName(),
                        $name,
                        'Span name does not match constraint'
                    );
                    $matchingSpan = $span;

                    break;
                } catch (AssertionFailedError $e) {
                    // This span doesn't match the constraint, skip it
                    continue;
                }
            }
        } else {
            foreach ($this->spans as $span) {
                if ($span->getName() === $name) {
                    $matchingSpan = $span;

                    break;
                }
            }
        }

        if (!$matchingSpan) {
            // Record the actual result
            $this->actualStructure[] = [
                'type' => 'missing_child_span',
                'expected_name' => $name instanceof Constraint ? 'constraint' : $name,
                'available_spans' => array_map(function ($span) {
                    return $span->getName();
                }, $this->spans),
            ];

            throw new TraceAssertionFailedException(
                sprintf(
                    'No span matching name "%s" found',
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

        return new SpanAssertion($matchingSpan, $this, null, $this->expectedStructure, $this->actualStructure);
    }

    /**
     * Assert that the trace has the expected number of root spans.
     *
     * @param int $count The expected number of root spans
     * @throws TraceAssertionFailedException
     * @return self
     */
    public function hasRootSpans(int $count): self
    {
        // Find root spans (spans without parents or with remote parents)
        $rootSpans = [];
        $spanMap = $this->buildSpanMap($this->spans);

        foreach ($spanMap as $_ => $data) {
            $span = $data['span'];
            $parentSpanId = $span->getParentSpanId();

            // A span is a root span if it has no parent or its parent is not in our map
            if (!$parentSpanId || !isset($spanMap[$parentSpanId])) {
                $rootSpans[] = $span;
            }
        }

        // Record the expectation
        $this->expectedStructure[] = [
            'type' => 'root_span_count',
            'count' => $count,
        ];

        // Record the actual result
        $this->actualStructure[] = [
            'type' => 'root_span_count',
            'count' => count($rootSpans),
            'spans' => array_map(function ($span) {
                return $span->getName();
            }, $rootSpans),
        ];

        try {
            Assert::assertCount(
                $count,
                $rootSpans,
                sprintf(
                    'Expected %d root spans, but found %d',
                    $count,
                    count($rootSpans)
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
     * Get the spans being asserted against.
     *
     * @return array
     */
    public function getSpans(): array
    {
        return $this->spans;
    }

    /**
     * Check if strict mode is enabled.
     *
     * @return bool
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function isStrict(): bool
    {
        return $this->strict;
    }

    /**
     * Return the trace assertion itself.
     * This method is used to maintain a consistent fluent interface.
     *
     * @return self
     */
    public function end(): self
    {
        return $this;
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

        /** @phpstan-ignore deadCode.unreachable */
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
