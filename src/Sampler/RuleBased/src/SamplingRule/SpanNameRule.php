<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use function preg_match;
use function sprintf;

final class SpanNameRule implements SamplingRule
{
    /**
     * @param non-empty-string $pattern
     */
    public function __construct(
        private readonly string $pattern,
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
        return (bool) preg_match($this->pattern, $spanName);
    }

    public function __toString(): string
    {
        return sprintf('SpanName{pattern=%s}', $this->pattern);
    }
}
