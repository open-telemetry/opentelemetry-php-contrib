<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Sampler\RuleBased;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Trace\LinkInterface;
use Stringable;

/**
 * @phan-suppress PhanRedefinedInheritedInterface
 */
interface SamplingRule extends Stringable
{
    /**
     * Returns whether this sampling rule matches the given data.
     *
     * @param ContextInterface $context parent context
     * @param string $traceId trace id in binary format
     * @param string $spanName span name
     * @param int $spanKind span kind
     * @param AttributesInterface $attributes span attributes
     * @param list<LinkInterface> $links span links
     * @return bool whether this rule matches the given data
     *
     * @see Sampler::shouldSample()
     */
    public function matches(
        ContextInterface $context,
        string $traceId,
        string $spanName,
        int $spanKind,
        AttributesInterface $attributes,
        array $links,
    ): bool;
}
