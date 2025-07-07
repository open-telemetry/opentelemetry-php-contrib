<?php
// src/Sampler/AWS/AWSXRayRemoteSampler.php

namespace OpenTelemetry\Contrib\Sampler\Xray;

use DateTimeImmutable;
use Exception;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SamplingResult;


class AWSXRayRemoteSampler implements SamplerInterface {
    private SamplerInterface $root;
    public function __construct(
        ResourceInfo $resource,
        string       $host,
        int          $rulePollingIntervalMillis = 60
    ) {
        $this->root = new ParentBased(new _AWSXRayRemoteSampler($resource, $host, $rulePollingIntervalMillis));
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
    // 5 minute default sampling rules polling interval
    private const DEFAULT_RULES_POLLING_INTERVAL_SECONDS = 5 * 60;
    // Default endpoint for awsproxy : https://aws-otel.github.io/docs/getting-started/remote-sampling#enable-awsproxy-extension
    private const DEFAULT_AWS_PROXY_ENDPOINT = 'http://localhost:2000';

    private Clock $clock;
    private RulesCache $rulesCache;
    private FallbackSampler $fallback;
    private AWSXRaySamplerClient $client;

    private int $rulePollingIntervalMillis;
    private int $targetPollingIntervalMillis;
    private DateTimeImmutable $nextRulesFetchTime;
    private DateTimeImmutable $nextTargetFetchTime;

    private int $rulePollingJitterMillis;
    private int $targetPollingJitterMillis;

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
        string       $awsProxyEndpoint = self::DEFAULT_AWS_PROXY_ENDPOINT,
        int          $pollingInterval = self::DEFAULT_RULES_POLLING_INTERVAL_SECONDS
    ) {
        $this->clock                   = new Clock();
        $this->fallback                = new FallbackSampler();
        $this->rulesCache              = new RulesCache(
            $this->clock,
            bin2hex(random_bytes(12)),
            $resource,
            $this->fallback
        );

        $this->rulePollingIntervalMillis = $pollingInterval * 1000;
        $this->rulePollingJitterMillis = rand(1, 5000);

        $this->targetPollingIntervalMillis = $this->rulesCache::DEFAULT_TARGET_INTERVAL_SEC * 1000;
        $this->targetPollingJitterMillis = rand(1, 100);

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
        $now                           = $this->clock->now();
        $this->nextRulesFetchTime      = $now->modify('+ '.$this->rulePollingJitterMillis + $this->rulePollingIntervalMillis.' milliseconds');
        $this->nextTargetFetchTime     = $now->modify('+ '.$this->targetPollingJitterMillis + $this->targetPollingIntervalMillis.' milliseconds');
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
    ): SamplingResult
    {
        $now = $this->clock->now();

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
                        if ($resp->LastRuleModification > $this->rulesCache->getUpdatedAt()->getTimestamp()) {
                            $this->getAndUpdateRules($now);
                        }
                    }
                }
            } catch (Exception $e) {
                //ignore for now
            }

            $nextTargetFetchTime = $this->rulesCache->nextTargetFetchTime();
            $nextTargetFetchInterval = $nextTargetFetchTime->getTimestamp() - $this->clock->now()->getTimestamp();
            if ($nextTargetFetchInterval < 0) {
                $nextTargetFetchInterval = $this->rulesCache::DEFAULT_TARGET_INTERVAL_SEC;
            }

            $nextTargetFetchInterval = $nextTargetFetchInterval * 1000;
            
            $this->nextTargetFetchTime = $now->modify('+ '.$this->targetPollingJitterMillis + $nextTargetFetchInterval.' milliseconds');

        }

        // 3) Delegate decision to rulesCache or fallback
        // if cache expired, fallback
        if ($this->rulesCache->expired()) {
            return $this->fallback->shouldSample($parentContext, $traceId, $spanName, $spanKind, $attributes, $links);
        }
        // delegate
        return $this->rulesCache->shouldSample($parentContext, $traceId, $spanName, $spanKind, $attributes, $links);
    }

    private function getAndUpdateRules(DateTimeImmutable $now)
    {
        try {
            $rules = $this->client->getSamplingRules();
            $this->rulesCache->updateRules($rules);
        } catch (Exception $e) {
            // ignore error
        }
        $this->nextRulesFetchTime = $now->modify('+ '.$this->rulePollingJitterMillis + $this->rulePollingIntervalMillis.' milliseconds');
    }

    public function getDescription(): string
    {
        return sprintf(
            '_AWSXRayRemoteSampler{awsProxyEndpoint=%s,rulePollingIntervalMillis=%ds}',
            $this->awsProxyEndpoint,
            $this->rulePollingIntervalMillis
        );
    }
}
