<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Sampler\RuleBased;

use OpenTelemetry\SDK\Trace\SamplerInterface;
use Stringable;

/**
 * @phan-suppress PhanRedefinedInheritedInterface
 */
interface RuleSetInterface extends Stringable
{
    /**
     * @return list<SamplingRule>
     */
    public function samplingRules(): array;
    public function delegate(): SamplerInterface;
}
