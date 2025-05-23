<?php
// src/Sampler/AWS/AWSXRayRemoteSampler.php
namespace OpenTelemetry\Contrib\Sampler\Xray;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use React\EventLoop\LoopInterface;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SamplingParameters;
use OpenTelemetry\SDK\Trace\SamplingResult;

class AWSXRayRemoteSampler extends SamplerInterface
{
    private const DEFAULT_TARGET_INTERVAL = 10;
    
    private ResourceInfo $resource;
    private int $pollInterval;
    private RulesCache $rulesCache;
    private AWSXRaySamplerClient $client;
    private FallbackSampler $fallback;
    private Clock $clock;
    
    public function __construct(
        ResourceInfo $resource,
        array        $awsConfig,
        int          $pollIntervalSec = 60
    ) {
        $this->resource    = $resource;
        $this->pollInterval= $pollIntervalSec;
        $this->clock       = new Clock();
        $this->client      = new AWSXRaySamplerClient($awsConfig);
        $this->fallback    = new FallbackSampler($this->clock);
        $this->rulesCache  = new RulesCache($this->clock, bin2hex(random_bytes(12)), $resource, $this->fallback);
        
        // initial rule load
        $this->rulesCache->updateRules($this->client->getSamplingRules());
    }
    
    /**
     * Call this once, passing in your ReactPHP loop, before starting your app.
     */
    public function start(LoopInterface $loop): void
    {
        // poll rules
        $loop->addPeriodicTimer($this->pollInterval, function () {
            $this->rulesCache->updateRules($this->client->getSamplingRules());
        });
        
        // poll targets
        $loop->addPeriodicTimer(self::DEFAULT_TARGET_INTERVAL, function () {
            $statsDocs = array_map(
                fn(SamplingStatisticsDocument $d) => (array)$d,
                array_map([$this->rulesCache, 'snapshot'], [$this->clock->now()])
            );
            $resp = $this->client->getSamplingTargets($statsDocs);
            if (isset($resp['SamplingTargetDocuments'])) {
                $map = [];
                foreach ($resp['SamplingTargetDocuments'] as $tgt) {
                    $map[$tgt['RuleName']] = (object)$tgt;
                }
                $this->rulesCache->updateTargets($map);
            }
        });
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
        // if cache expired, fallback
        if ($this->rulesCache->expired()) {
            return $this->fallback->shouldSample($parentContext, $traceId, $spanName, $spanKind, $attributes, $links);
        }
        // delegate
        return $this->rulesCache->shouldSample($parentContext, $traceId, $spanName, $spanKind, $attributes, $links);
    }
    
    public function getDescription(): string
    {
        return 'AWSXRayRemoteSampler{pollInterval='.$this->pollInterval.'s}';
    }
}
