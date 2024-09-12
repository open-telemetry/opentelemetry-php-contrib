<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Sampler\RuleBased;

use function implode;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SamplingResult;
use function sprintf;

/**
 * Samples based on a list of rule sets. The first matching rule set will be
 * used for sampling decisions.
 */
final class RuleBasedSampler implements SamplerInterface
{
    /**
     * @param list<RuleSet> $ruleSets
     */
    public function __construct(
        private readonly array $ruleSets,
        private readonly SamplerInterface $fallback,
    ) {
    }

    public function shouldSample(
        ContextInterface $parentContext,
        string $traceId,
        string $spanName,
        int $spanKind,
        AttributesInterface $attributes,
        array $links,
    ): SamplingResult {
        foreach ($this->ruleSets as $ruleSet) {
            foreach ($ruleSet->samplingRules() as $samplingRule) {
                if (!$samplingRule->matches($parentContext, $traceId, $spanName, $spanKind, $attributes, $links)) {
                    continue 2;
                }
            }

            return $ruleSet->delegate()->shouldSample($parentContext, $traceId, $spanName, $spanKind, $attributes, $links);
        }

        return $this->fallback->shouldSample($parentContext, $traceId, $spanName, $spanKind, $attributes, $links);
    }

    public function __toString(): string
    {
        return sprintf('RuleBasedSampler{rules=[%s],fallback=%s}', implode(',', $this->ruleSets), $this->fallback->getDescription());
    }

    public function getDescription(): string
    {
        return (string) $this;
    }
}
