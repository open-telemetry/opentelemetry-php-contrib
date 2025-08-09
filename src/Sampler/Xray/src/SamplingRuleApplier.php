<?php

declare(strict_types=1);
// src/Sampler/AWS/SamplingRuleApplier.php

namespace OpenTelemetry\Contrib\Sampler\Xray;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SamplingResult;
use OpenTelemetry\SemConv\TraceAttributes;

class SamplingRuleApplier
{
    private string $clientId;
    private SamplingRule $rule;
    private SamplerInterface $reservoirSampler;
    private SamplerInterface $fixedRateSampler;
    private bool $borrowing;
    private Statistics $statistics;
    private int $reservoirEndTime;
    private int $nextSnapshotTime;
    private string $ruleName;
    
    public function __construct(string $clientId, SamplingRule $rule, ?Statistics $stats = null)
    {
        $this->clientId   = $clientId;
        $this->rule       = $rule;
        $this->ruleName   = $rule->RuleName;
        $this->statistics = $stats ?? new Statistics();
        
        if ($rule->ReservoirSize > 0) {
            $this->reservoirSampler = new RateLimitingSampler($rule->ReservoirSize);
            $this->borrowing = true;
        } else {
            $this->reservoirSampler = new AlwaysOffSampler();
            $this->borrowing = false;
        }
        
        $this->fixedRateSampler = new TraceIdRatioBasedSampler($rule->FixedRate);
        $this->reservoirEndTime = PHP_INT_MAX;
        $this->nextSnapshotTime = Clock::getDefault()->now();
    }
    
    public function matches(AttributesInterface $attributes, ResourceInfo $resource): bool
    {
        // Extract HTTP path
        $httpTarget = $attributes->get(TraceAttributes::HTTP_TARGET) ?? $attributes->get(TraceAttributes::URL_PATH); // @phan-suppress-current-line PhanDeprecatedClassConstant
        $httpUrl = $attributes->get(TraceAttributes::HTTP_URL) ?? $attributes->get(TraceAttributes::URL_FULL); // @phan-suppress-current-line PhanDeprecatedClassConstant
        if ($httpTarget == null && isset($httpUrl)) {
            $httpTarget = parse_url($httpUrl, PHP_URL_PATH);
            $httpTarget = $httpTarget ? $httpTarget : null;
        }

        $httpMethod = $attributes->get(TraceAttributes::HTTP_METHOD) ?? $attributes->get(TraceAttributes::HTTP_REQUEST_METHOD); // @phan-suppress-current-line PhanDeprecatedClassConstant
        if ($httpMethod == '_OTHER') {
            $httpMethod = $attributes->get(TraceAttributes::HTTP_REQUEST_METHOD_ORIGINAL);
        }
        $httpHost   = $attributes->get(TraceAttributes::HTTP_HOST)   ?? $attributes->get(TraceAttributes::SERVER_ADDRESS); // @phan-suppress-current-line PhanDeprecatedClassConstant
        $serviceName= $resource->getAttributes()->get(TraceAttributes::SERVICE_NAME) ?? '';
        $cloudPlat  = $resource->getAttributes()->get(TraceAttributes::CLOUD_PLATFORM) ?? '';
        $serviceType= Matcher::getXRayCloudPlatform($cloudPlat);

        // ARN: ECS container ARN or Lambda faas.id
        $arn = $resource->getAttributes()->get('aws.ecs.container.arn')
            ?? ($serviceType === 'AWS::Lambda::Function' ? ($attributes->get('faas.id') ?? null) : null)
            ?? '';

        return Matcher::attributeMatch($attributes->toArray(), $this->rule->Attributes)
            && Matcher::wildcardMatch($httpTarget, $this->rule->UrlPath)
            && Matcher::wildcardMatch($httpMethod, $this->rule->HttpMethod)
            && Matcher::wildcardMatch($httpHost, $this->rule->Host)
            && Matcher::wildcardMatch($serviceName, $this->rule->ServiceName)
            && Matcher::wildcardMatch($serviceType, $this->rule->ServiceType)
            && Matcher::wildcardMatch($arn, $this->rule->ResourceArn);
    }

    /** @psalm-suppress ArgumentTypeCoercion */
    public function shouldSample(
        ContextInterface $parentContext,
        string $traceId,
        string $spanName,
        int $spanKind,
        AttributesInterface $attributes,
        array $links,
    ): SamplingResult {
        $this->statistics->requestCount++;
        $now = Clock::getDefault()->now();
        if ($now < $this->reservoirEndTime) {
            $res = $this->reservoirSampler->shouldSample($parentContext, $traceId, $spanName, $spanKind, $attributes, $links);
            ;
            if ($res->getDecision() !== SamplingResult::DROP) {
                if ($this->borrowing) {
                    $this->statistics->borrowCount++;
                }
                $this->statistics->sampleCount++;

                return $res;
            }
        }

        $res = $this->fixedRateSampler->shouldSample($parentContext, $traceId, $spanName, $spanKind, $attributes, $links);
        if ($res->getDecision() !== SamplingResult::DROP) {
            $this->statistics->sampleCount++;
        }

        return $res;
    }
    
    public function snapshot(int $now): SamplingStatisticsDocument
    {
        $ts = intdiv($now, ClockInterface::NANOS_PER_MILLISECOND);
        $req = $this->statistics->requestCount;
        $smp = $this->statistics->sampleCount;
        $brw = $this->statistics->borrowCount;
        // reset
        $this->statistics->requestCount = 0;
        $this->statistics->sampleCount  = 0;
        $this->statistics->borrowCount  = 0;
        
        return new SamplingStatisticsDocument(
            $this->clientId,
            $this->ruleName,
            $req,
            $smp,
            $brw,
            $ts
        );
    }
    
    /**
     * Apply an AWS X-Ray SamplingTargets response to this rule applier,
     * returning a new applier with updated reservoir & fixed-rate samplers.
     *
     * @param object             $targetDoc  stdClass from AWS SDK getSamplingTargets()
     * @param int                $now        “now” timestamp for computing next snapshot
     */
    public function withTarget(object $targetDoc, int $now): self
    {
        // 1) Determine new fixed-rate sampler
        if (isset($targetDoc->FixedRate)) {
            $newFixedRateSampler = new TraceIdRatioBasedSampler((float) $targetDoc->FixedRate);
        } else {
            $newFixedRateSampler = $this->fixedRateSampler;
        }

        // 2) Determine new reservoir sampler & end time
        $newReservoirEndTime = PHP_INT_MAX;
        if (isset($targetDoc->ReservoirQuota, $targetDoc->ReservoirQuotaTTL)) {
            $quota      = (int) floor($targetDoc->ReservoirQuota);
            $ttlSeconds = (int) floor($targetDoc->ReservoirQuotaTTL);
            $newReservoirSampler = $quota > 0
                ? new RateLimitingSampler($quota)
                : new AlwaysOffSampler();
            $newReservoirEndTime = $ttlSeconds * ClockInterface::NANOS_PER_SECOND
                ?: $newReservoirEndTime;
        } else {
            // if no quota provided, turn off reservoir
            $newReservoirSampler = new AlwaysOffSampler();
        }

        // 3) Next snapshot time (Interval in seconds, defaulting to 10s)
        $intervalSec = isset($targetDoc->Interval)
            ? (int) $targetDoc->Interval
            : 10;
        $newNextSnapshotTime = $now + ($intervalSec * ClockInterface::NANOS_PER_SECOND);

        // 4) Clone & patch
        $clone = clone $this;
        $clone->fixedRateSampler   = $newFixedRateSampler;
        $clone->reservoirSampler   = $newReservoirSampler;
        $clone->borrowing          = false;               // once we’ve applied a target, no more borrowing
        $clone->reservoirEndTime   = $newReservoirEndTime;
        $clone->nextSnapshotTime   = $newNextSnapshotTime;

        return $clone;
    }

    public function getNextSnapshotTime(): int
    {
        return $this->nextSnapshotTime;
    }
    
    public function getRuleName(): string
    {
        return $this->ruleName;
    }

    public function setRule($rule)
    {
        $this->rule = $rule;
    }
}
