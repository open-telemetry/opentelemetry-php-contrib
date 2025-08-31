<?php

declare(strict_types=1);
// src/Sampler/AWS/RateLimitingSampler.php

namespace OpenTelemetry\Contrib\Sampler\Xray;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SamplingResult;

/**
 * A sampler that allows up to N recordings per second (reservoir),
 * dropping all others.
 */
class RateLimitingSampler implements SamplerInterface
{
    private RateLimiter $limiter;

    /**
     * @param int   $maxTracesPerSecond  Maximum traces to sample per second.
     */
    public function __construct(int $maxTracesPerSecond)
    {
        $this->limiter = new RateLimiter($maxTracesPerSecond);
    }

    public function shouldSample(
        ContextInterface $parentContext,
        string $traceId,
        string $spanName,
        int $spanKind,
        AttributesInterface $attributes,
        array $links,
    ): SamplingResult {
        if ($this->limiter->tryAcquire()) {
            return new SamplingResult(SamplingResult::RECORD_AND_SAMPLE, [], null);
        }

        return new SamplingResult(SamplingResult::DROP, [], null);
    }

    public function getDescription(): string
    {
        return sprintf('RateLimitingSampler{rate limiting sampling with sampling config of %d req/sec and 0%% of additional requests}', $this->limiter->getCapacity());
    }
}
