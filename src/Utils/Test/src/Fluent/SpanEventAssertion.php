<?php

declare(strict_types=1);

namespace OpenTelemetry\TestUtils\Fluent;

use OpenTelemetry\SDK\Trace\EventInterface;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Fluent interface for asserting span event properties.
 */
class SpanEventAssertion
{
    /** @var EventInterface */
    private EventInterface $event;

    /** @var SpanAssertion */
    private SpanAssertion $spanAssertion;

    /** @var array */
    private array $expectedStructure;

    /** @var array */
    private array $actualStructure;

    /**
     * @param EventInterface $event The event to assert against
     * @param SpanAssertion $spanAssertion The parent span assertion
     * @param array $expectedStructure The expected structure
     * @param array $actualStructure The actual structure
     */
    public function __construct(
        EventInterface $event,
        SpanAssertion $spanAssertion,
        array $expectedStructure = [],
        array $actualStructure = []
    ) {
        $this->event = $event;
        $this->spanAssertion = $spanAssertion;
        $this->expectedStructure = $expectedStructure;
        $this->actualStructure = $actualStructure;
    }

    /**
     * Assert that the event has an attribute with the expected key and value.
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
            'type' => 'span_event_attribute',
            'key' => $key,
            'value' => $value instanceof Constraint ? 'constraint' : $value,
        ];

        // Check if the attribute exists
        if (!$this->event->getAttributes()->has($key)) {
            // Record the actual result
            $this->actualStructure[] = [
                'type' => 'missing_span_event_attribute',
                'key' => $key,
                'available_attributes' => array_keys($this->event->getAttributes()->toArray()),
            ];

            throw new TraceAssertionFailedException(
                sprintf(
                    "Event '%s' is missing attribute '%s'",
                    $this->event->getName(),
                    $key
                ),
                $this->expectedStructure,
                $this->actualStructure
            );
        }

        // Get the actual value
        $actualValue = $this->event->getAttributes()->get($key);

        // Record the actual result
        $this->actualStructure[] = [
            'type' => 'span_event_attribute',
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
                        "Event '%s' attribute '%s' does not match constraint",
                        $this->event->getName(),
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
                        "Event '%s' attribute '%s' expected value %s, but got %s",
                        $this->event->getName(),
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
     * Assert that the event has the expected attributes.
     *
     * @param array $attributes The expected attributes
     * @throws TraceAssertionFailedException
     * @return self
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function withAttributes(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->withAttribute($key, $value);
        }

        return $this;
    }

    /**
     * Return to the parent span assertion.
     *
     * @psalm-suppress UnusedMethodCall
     * @return SpanAssertion
     */
    public function end(): SpanAssertion
    {
        return $this->spanAssertion;
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
}
