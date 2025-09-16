<?php

declare(strict_types=1);
// src/Sampler/AWS/RulesCache.php

namespace OpenTelemetry\Contrib\Sampler\Xray;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SamplingResult;

class RulesCache implements SamplerInterface
{
    private const CACHE_TTL = 3600 * ClockInterface::NANOS_PER_SECOND; // 1hr
    public const DEFAULT_TARGET_INTERVAL_SEC = 10;
    private string $clientId;
    private ResourceInfo $resource;
    private SamplerInterface $fallbackSampler;
    /** @var SamplingRuleApplier[] */
    private array $appliers = [];
    private int $updatedAt;
    
    public function __construct(string $clientId, ResourceInfo $resource, SamplerInterface $fallback)
    {
        $this->clientId = $clientId;
        $this->resource = $resource;
        $this->fallbackSampler = $fallback;
        $this->updatedAt = Clock::getDefault()->now();
    }
    
    public function expired(): bool
    {
        return Clock::getDefault()->now() > $this->updatedAt + self::CACHE_TTL;
    }
    
    public function updateRules(array $newRules): void
    {
        usort($newRules, fn (SamplingRule $a, SamplingRule $b) => $a->compareTo($b));
        $newAppliers = [];
        foreach ($newRules as $rule) {
            // reuse existing applier if same ruleName
            $found = null;
            foreach ($this->appliers as $ap) {
                if ($ap->getRuleName() === $rule->RuleName) {
                    $found = $ap;

                    break;
                }
            }
            $applier = $found ?? new SamplingRuleApplier($this->clientId, $rule);
            
            // update rule in applier
            $applier->setRule($rule);
            $newAppliers[] = $applier;
        }
        $this->appliers  = $newAppliers;
        $this->updatedAt = Clock::getDefault()->now();
    }
    
    public function shouldSample(
        ContextInterface $parentContext,
        string $traceId,
        string $spanName,
        int $spanKind,
        AttributesInterface $attributes,
        array $links,
    ): SamplingResult {
        foreach ($this->appliers as $applier) {
            if ($applier->matches($attributes, $this->resource)) {
                return $applier->shouldSample($parentContext, $traceId, $spanName, $spanKind, $attributes, $links);
            }
        }

        // fallback if no rule matched
        return $this->fallbackSampler->shouldSample($parentContext, $traceId, $spanName, $spanKind, $attributes, $links);
    }
    
    public function nextTargetFetchTime(): int
    {
        $defaultPollingTime = Clock::getDefault()->now() + (self::DEFAULT_TARGET_INTERVAL_SEC * ClockInterface::NANOS_PER_SECOND);
        
        if (empty($this->appliers)) {
            return $defaultPollingTime;
        }
        $times = array_map(fn ($a) => $a->getNextSnapshotTime(), $this->appliers);
        $min = min($times);

        return $min < Clock::getDefault()->now()
            ? $defaultPollingTime
            : $min;
    }
    
    /** Update reservoir/fixed rates from GetSamplingTargets response */
    public function updateTargets(array $targets): void
    {
        $new = [];
        foreach ($this->appliers as $applier) {
            $name = $applier->getRuleName();
            if (isset($targets[$name])) {
                $new[] = $applier->withTarget($targets[$name], Clock::getDefault()->now());
            } else {
                $new[] = $applier;
            }
        }
        $this->appliers = $new;
    }

    public function getAppliers(): array
    {
        return $this->appliers;
    }

    public function getDescription(): string
    {
        return 'RulesCache';
    }

    public function getUpdatedAt(): int
    {
        return $this->updatedAt;
    }
}
