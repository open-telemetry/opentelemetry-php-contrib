<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use function sprintf;

/**
 * Checks whether the span kind matches a specific span kind.
 */
final class SpanKindRule implements SamplingRule
{

    public function __construct(
        private readonly int $spanKind,
    ) {
    }

    public function matches(
        ContextInterface $context,
        string $traceId,
        string $spanName,
        int $spanKind,
        AttributesInterface $attributes,
        array $links,
    ): bool {
        return $this->spanKind === $spanKind;
    }

    public function __toString(): string
    {
        return sprintf('SpanKind{kind=%s}', $this->spanKind); //@todo SpanKind enum?
    }
}
