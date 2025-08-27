<?php

declare(strict_types=1);
// src/Sampler/AWS/AWSXRayRemoteSampler.php

namespace OpenTelemetry\Contrib\Sampler\Xray;

use Exception;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SamplingResult;

/** @psalm-suppress UnusedClass */
class AWSXRayRemoteSampler implements SamplerInterface
{
    // 5 minute default sampling rules polling interval
    private const DEFAULT_RULES_POLLING_INTERVAL_SECONDS = 5 * 60;
    // Default endpoint for awsproxy : https://aws-otel.github.io/docs/getting-started/remote-sampling#enable-awsproxy-extension
    private const DEFAULT_AWS_PROXY_ENDPOINT = 'http://localhost:2000';

    private SamplerInterface $root;
    
    /**
     * @param ResourceInfo $resource
     *   Must contain attributes like service.name, cloud.platform, etc.
     * @param string       $host
     *   X-Ray host, e.g. "xray.us-west-2.amazonaws.com"
     * @param int          $pollingInterval
     *   Base interval (seconds) between rule fetches (will be jittered).
     */
    public function __construct(
        ResourceInfo $resource,
        string $host = self::DEFAULT_AWS_PROXY_ENDPOINT,
        int $pollingInterval = self::DEFAULT_RULES_POLLING_INTERVAL_SECONDS
    ) {
        // pollingInterval shouldn't be less than 10 seconds
        if ($pollingInterval < 10) {
            $pollingInterval = self::DEFAULT_RULES_POLLING_INTERVAL_SECONDS;
        }
        
        $this->root = new ParentBased(new _AWSXRayRemoteSampler($resource, $host, $pollingInterval));
    }

    public function shouldSample(
        ContextInterface $parentContext,
        string $traceId,
        string $spanName,
        int $spanKind,
        AttributesInterface $attributes,
        array $links,
    ): SamplingResult {
        return $this->root->shouldSample($parentContext, $traceId, $spanName, $spanKind, $attributes, $links);
    }

    public function getDescription(): string
    {
        return sprintf(
            'AWSXRayRemoteSampler{root=%s}',
            $this->root->getDescription()
        );
    }
}

class _AWSXRayRemoteSampler implements SamplerInterface
{
    private RulesCache $rulesCache;
    private FallbackSampler $fallback;
    private AWSXRaySamplerClient $client;

    private int $rulePollingIntervalNanos;
    /** @psalm-suppress UnusedProperty */
    private int $targetPollingIntervalNanos;

    // the times below are in nanoseconds
    private int $nextRulesFetchTime;
    private int $nextTargetFetchTime;

    private int $rulePollingJitterNanos;
    private int $targetPollingJitterNanos;

    private string $awsProxyEndpoint;

    /**
     * @param ResourceInfo $resource
     *   Must contain attributes like service.name, cloud.platform, etc.
     * @param string       $awsProxyEndpoint
     *   X-Ray awsProxyEndpoint, e.g. "xray.us-west-2.amazonaws.com"
     * @param int          $pollingInterval
     *   Base interval (seconds) between rule fetches (will be jittered).
     */
    public function __construct(
        ResourceInfo $resource,
        string $awsProxyEndpoint,
        int $pollingInterval
    ) {
        $this->fallback                = new FallbackSampler();
        $this->rulesCache              = new RulesCache(
            bin2hex(random_bytes(12)),
            $resource,
            $this->fallback
        );

        $this->rulePollingIntervalNanos = $pollingInterval * ClockInterface::NANOS_PER_SECOND;
        $this->rulePollingJitterNanos = rand(1, 5000) * ClockInterface::NANOS_PER_MILLISECOND;

        $this->targetPollingIntervalNanos = $this->rulesCache::DEFAULT_TARGET_INTERVAL_SEC * ClockInterface::NANOS_PER_SECOND;
        $this->targetPollingJitterNanos = rand(1, 100) * ClockInterface::NANOS_PER_MILLISECOND;

        $this->awsProxyEndpoint = $awsProxyEndpoint;

        $this->client                  = new AWSXRaySamplerClient($awsProxyEndpoint);

        // 1) Initial fetch of rules
        try {
            $initialRules = $this->client->getSamplingRules();
            $this->rulesCache->updateRules($initialRules);
        } catch (Exception $e) {
            // ignore failures
        }

        // 2) Schedule next fetch times with jitter
        $now                           = Clock::getDefault()->now();
        $this->nextRulesFetchTime      = $now + ($this->rulePollingJitterNanos + $this->rulePollingIntervalNanos);
        $this->nextTargetFetchTime     = $now + ($this->targetPollingJitterNanos + $this->targetPollingIntervalNanos);
    }

    /**
     * Called on each sampling decision. If it’s time, refresh rules or targets.
     */
    public function shouldSample(
        ContextInterface $parentContext,
        string $traceId,
        string $spanName,
        int $spanKind,
        AttributesInterface $attributes,
        array $links,
    ): SamplingResult {
        $now = Clock::getDefault()->now();

        // 1) Refresh rules if needed
        if ($now >= $this->nextRulesFetchTime) {
            $this->getAndUpdateRules($now);
        }

        // 2) Refresh targets if needed
        if ($now >= $this->nextTargetFetchTime) {
            $appliers  = $this->rulesCache->getAppliers();
            $statsDocs = [];
            foreach ($appliers as $applier) {
                $statsDocs[] = $applier->snapshot($now);
            }

            try {
                $resp = $this->client->getSamplingTargets($statsDocs);
                if ($resp !== null && isset($resp->SamplingTargetDocuments)) {
                    $map = [];
                    foreach ($resp->SamplingTargetDocuments as $tgt) {
                        $map[$tgt->RuleName] = $tgt;
                    }
                    $this->rulesCache->updateTargets($map);

                    if (isset($resp->LastRuleModification) && $resp->LastRuleModification > 0) {
                        if (($resp->LastRuleModification * ClockInterface::NANOS_PER_SECOND) > $this->rulesCache->getUpdatedAt()) {
                            $this->getAndUpdateRules($now);
                        }
                    }
                }
            } catch (Exception $e) {
                //ignore for now
            }

            $nextTargetFetchTime = $this->rulesCache->nextTargetFetchTime();
            $nextTargetFetchInterval = $nextTargetFetchTime - Clock::getDefault()->now();
            if ($nextTargetFetchInterval < 0) {
                $nextTargetFetchInterval = $this->rulesCache::DEFAULT_TARGET_INTERVAL_SEC * ClockInterface::NANOS_PER_SECOND;
            }
            $this->nextTargetFetchTime = $now + ($this->targetPollingJitterNanos + $nextTargetFetchInterval);
        }

        // 3) Delegate decision to rulesCache or fallback
        // if cache expired, fallback
        if ($this->rulesCache->expired()) {
            return $this->fallback->shouldSample($parentContext, $traceId, $spanName, $spanKind, $attributes, $links);
        }

        // delegate
        return $this->rulesCache->shouldSample($parentContext, $traceId, $spanName, $spanKind, $attributes, $links);
    }

    private function getAndUpdateRules(int $now)
    {
        try {
            $rules = $this->client->getSamplingRules();
            $this->rulesCache->updateRules($rules);
        } catch (Exception $e) {
            // ignore error
        }
        $this->nextRulesFetchTime = $now + ($this->rulePollingJitterNanos + $this->rulePollingIntervalNanos);
    }

    public function getDescription(): string
    {
        return sprintf(
            '_AWSXRayRemoteSampler{awsProxyEndpoint=%s,rulePollingIntervalNanos=%ds}',
            $this->awsProxyEndpoint,
            $this->rulePollingIntervalNanos
        );
    }
}
