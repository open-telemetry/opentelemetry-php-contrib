<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use function sprintf;
use function var_export;

/**
 * Checks whether the parent matches sampled and remote.
 */
final class ParentRule implements SamplingRule
{

    public function __construct(
        private readonly bool $sampled,
        private readonly ?bool $remote = null,
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
        $parent = Span::fromContext($context)->getContext();

        return $parent->isValid()
            && $parent->isSampled() === $this->sampled
            && ($this->remote === null || $parent->isRemote() === $this->remote);
    }

    public function __toString(): string
    {
        return sprintf('Parent{sampled=%s,remote=%s}', var_export($this->sampled, true), var_export($this->remote, true));
    }
}
