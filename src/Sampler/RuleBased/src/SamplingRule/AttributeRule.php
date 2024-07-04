<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use function preg_match;
use function sprintf;

/**
 * Checks whether an attribute value matches a regex pattern.
 */
final class AttributeRule implements SamplingRule
{
    /**
     * @param non-empty-string $attributeKey
     * @param non-empty-string $pattern
     */
    public function __construct(
        private readonly string $attributeKey,
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
        return $attributes->has($this->attributeKey)
            && preg_match($this->pattern, (string) $attributes->get($this->attributeKey));
    }

    public function __toString(): string
    {
        return sprintf('Attribute{key=%s,pattern=%s}', $this->attributeKey, $this->pattern);
    }
}
