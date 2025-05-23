<?php
// src/Sampler/AWS/SamplingRuleApplier.php
namespace OpenTelemetry\Contrib\Sampler\Xray;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SamplingParameters;
use OpenTelemetry\SDK\Trace\SamplingResult;
use OpenTelemetry\SDK\Trace\SamplingDecision;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;

class SamplingRuleApplier
{
    private string $clientId;
    private SamplingRule $rule;
    private Clock $clock;
    private SamplerInterface $reservoirSampler;
    private SamplerInterface $fixedRateSampler;
    private bool $borrowing;
    private Statistics $statistics;
    private \DateTimeImmutable $reservoirEndTime;
    private \DateTimeImmutable $nextSnapshotTime;
    private string $ruleName;
    
    public function __construct(string $clientId, Clock $clock, SamplingRule $rule, ?Statistics $stats = null)
    {
        $this->clientId   = $clientId;
        $this->clock      = $clock;
        $this->rule       = $rule;
        $this->ruleName   = $rule->RuleName;
        $this->statistics = $stats ?? new Statistics();
        
        if ($rule->ReservoirSize > 0) {
            $this->reservoirSampler = new RateLimitingSampler($rule->ReservoirSize, $clock);
            $this->borrowing = true;
        } else {
            $this->reservoirSampler = new AlwaysOffSampler();
            $this->borrowing = false;
        }
        
        $this->fixedRateSampler = new TraceIdRatioBasedSampler($rule->FixedRate);
        $this->reservoirEndTime = new \DateTimeImmutable('@'.PHP_INT_MAX);
        $this->nextSnapshotTime = $clock->now();
    }

    /**
     * Private full constructor: accept *all* fields.
     * Used by withTarget() to clone with new samplers & timings.
     */
    private function __constructFull(
        string            $clientId,
        Clock             $clock,
        SamplingRule      $rule,
        SamplerInterface  $reservoirSampler,
        SamplerInterface  $fixedRateSampler,
        bool              $borrowing,
        Statistics        $statistics,
        \DateTimeImmutable $reservoirEndTime,
        \DateTimeImmutable $nextSnapshotTime
    ) {
        $this->clientId        = $clientId;
        $this->clock           = $clock;
        $this->rule            = $rule;
        $this->reservoirSampler= $reservoirSampler;
        $this->fixedRateSampler= $fixedRateSampler;
        $this->borrowing       = $borrowing;
        $this->statistics      = $statistics;
        $this->reservoirEndTime= $reservoirEndTime;
        $this->nextSnapshotTime= $nextSnapshotTime;
    }
    
    
    public function matches(AttributesInterface $attributes, ResourceInfo $resource): bool
    {
        // Extract HTTP path
        $httpTarget = $attributes->get('http.target') 
            ?? (
                null !== $attributes->get('http.url')
                    ? (preg_match('~^[^:]+://[^/]+(/.*)?$~', $attributes->get('http.url'), $m) 
                        ? ($m[1] ?? '/') 
                        : null
                    )
                    : null
            );

        $httpMethod = $attributes->get('http.method') ?? null;
        $httpHost   = $attributes->get('http.host')   ?? null;
        $serviceName= $resource->getAttributes()->get('service.name') ?? '';
        $cloudPlat  = $resource->getAttributes()->get('cloud.platform') ?? null;
        $serviceType= Matcher::getXRayCloudPlatform($cloudPlat);

        // ARN: ECS container ARN or Lambda faas.id
        $arn = $resource->getAttributes()->get('aws.ecs.container.arn')
            ?? ($serviceType === 'AWS::Lambda::Function' ? ($attributes->get('faas.id') ?? null) : null)
            ?? '';

        return Matcher::attributeMatch($attributes->toArray(), $this->rule->Attributes)
            && Matcher::wildcardMatch($httpTarget,    $this->rule->UrlPath)
            && Matcher::wildcardMatch($httpMethod,    $this->rule->HttpMethod)
            && Matcher::wildcardMatch($httpHost,      $this->rule->Host)
            && Matcher::wildcardMatch($serviceName,   $this->rule->ServiceName)
            && Matcher::wildcardMatch($serviceType,   $this->rule->ServiceType)
            && Matcher::wildcardMatch($arn,           $this->rule->ResourceArn);
    }

    
    public function shouldSample(
        ContextInterface $parentContext,
        string $traceId,
        string $spanName,
        int $spanKind,
        AttributesInterface $attributes,
        array $links,
    ): SamplingResult 
    {
        $this->statistics->requestCount++;
        $now = $this->clock->now();
        if ($now < $this->reservoirEndTime) {
            $res = $this->reservoirSampler->shouldSample($parentContext, $traceId, $spanName, $spanKind, $attributes, $links);;
            if ($res->getDecision() !== SamplingResult::DROP) {
                if ($this->borrowing) {
                    $this->statistics->borrowCount++;
                }
                $this->statistics->sampleCount++;
                return $res;
            }
        }
        $res = $this->fixedRateSampler->shouldSample($parentContext, $traceId, $spanName, $spanKind, $attributes, $links);;
        if ($res->getDecision() !== SamplingResult::DROP) {
            $this->statistics->sampleCount++;
        }
        return $res;
    }
    
    public function snapshot(\DateTimeImmutable $now): SamplingStatisticsDocument
    {
        $ts = $this->clock->toUnixMillis($now);
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
     * @param \DateTimeImmutable $now        “now” timestamp for computing next snapshot
     */
    public function withTarget(object $targetDoc, \DateTimeImmutable $now): self
    {
        // 1) Determine new fixed-rate sampler
        if (isset($targetDoc->FixedRate)) {
            $newFixedRateSampler = new TraceIdRatioBasedSampler((float) $targetDoc->FixedRate);
        } else {
            $newFixedRateSampler = $this->fixedRateSampler;
        }

        // 2) Determine new reservoir sampler & end time
        $newReservoirEndTime = new \DateTimeImmutable('9999-12-31T23:59:59+00:00');
        if (isset($targetDoc->ReservoirQuota, $targetDoc->ReservoirQuotaTTL)) {
            $quota      = (int) floor($targetDoc->ReservoirQuota);
            $ttlSeconds = (int) floor($targetDoc->ReservoirQuotaTTL);
            $newReservoirSampler = $quota > 0
                ? new RateLimitingSampler($quota)
                : new AlwaysOffSampler();
            $newReservoirEndTime = \DateTimeImmutable::createFromFormat('U', (string)$ttlSeconds)
                ?: $newReservoirEndTime;
        } else {
            // if no quota provided, turn off reservoir
            $newReservoirSampler = new AlwaysOffSampler();
        }

        // 3) Next snapshot time (Interval in seconds, defaulting to 10s)
        $intervalSec = isset($targetDoc->Interval)
            ? (int) $targetDoc->Interval
            : 10;
        $newNextSnapshotTime = $now->add(new \DateInterval("PT{$intervalSec}S"));

        // 4) Clone & patch
        $clone = clone $this;
        $clone->fixedRateSampler   = $newFixedRateSampler;
        $clone->reservoirSampler   = $newReservoirSampler;
        $clone->borrowing          = false;               // once we’ve applied a target, no more borrowing
        $clone->reservoirEndTime   = $newReservoirEndTime;
        $clone->nextSnapshotTime   = $newNextSnapshotTime;

        return $clone;
    }

    
    public function getNextSnapshotTime(): \DateTimeImmutable
    {
        return $this->nextSnapshotTime;
    }
    
    public function getRuleName(): string
    {
        return $this->ruleName;
    }
}
