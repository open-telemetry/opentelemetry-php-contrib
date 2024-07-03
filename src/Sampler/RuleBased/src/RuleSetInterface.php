<?php

namespace OpenTelemetry\Contrib\Sampler\RuleBased;

use OpenTelemetry\SDK\Trace\SamplerInterface;

interface RuleSetInterface
{
    /**
     * @return list<SamplingRule>
     */
    public function samplingRules(): array;
    public function delegate(): SamplerInterface;
    public function __toString();
}