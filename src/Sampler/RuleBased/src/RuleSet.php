<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Contrib\Sampler;

use Nevay\OTelSDK\Trace\Sampler;
use function implode;
use function sprintf;

final class RuleSet {

    /**
     * @param list<SamplingRule> $samplingRules
     */
    public function __construct(
        public readonly array $samplingRules,
        public readonly Sampler $delegate,
    ) {}

    public function __toString(): string {
        return sprintf('RuleSet{rules=[%s],delegate=%s}', implode(',', $this->samplingRules), $this->delegate);
    }
}
