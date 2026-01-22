<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Sampler\RuleBased;

use function implode;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use function sprintf;

final readonly class RuleSet implements RuleSetInterface
{
    /**
     * @param list<SamplingRule> $samplingRules
     */
    public function __construct(
        private array $samplingRules,
        private SamplerInterface $delegate,
    ) {
    }

    public function __toString(): string
    {
        return sprintf('RuleSet{rules=[%s],delegate=%s}', implode(',', $this->samplingRules), $this->delegate->getDescription());
    }

    /**
     * @return list<SamplingRule>
     */
    public function samplingRules(): array
    {
        return $this->samplingRules;
    }

    public function delegate(): SamplerInterface
    {
        return $this->delegate;
    }
}
