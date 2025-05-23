<?php
// src/Sampler/AWS/FallbackSampler.php
namespace OpenTelemetry\Contrib\Sampler\Xray;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SamplingResult;

class FallbackSampler extends SamplerInterface
{
    private SamplerInterface $reservoir;
    private SamplerInterface $fixedRate;
    
    public function __construct(Clock $clock)
    {
        $this->reservoir = new RateLimitingSampler(1, $clock);  // 1/sec
        $this->fixedRate = new TraceIdRatioBasedSampler(0.05);  // 5%
    }
    
    public function shouldSample(
        ContextInterface $parentContext,
        string $traceId,
        string $spanName,
        int $spanKind,
        AttributesInterface $attributes,
        array $links,
    ): SamplingResult {
        $res = $this->reservoir->shouldSample($parentContext, $traceId, $spanName, $spanKind, $attributes, $links);
        if ($res->getDecision() !== SamplingResult::DROP) {
            return $res;
        }
        return $this->fixedRate->shouldSample($parentContext, $traceId, $spanName, $spanKind, $attributes, $links);
    }
    
    public function getDescription(): string
    {
        return 'AWSXRayFallbackSampler';
    }
}
