<?php

declare(strict_types=1);
// src/Sampler/AWS/SamplingRule.php

namespace OpenTelemetry\Contrib\Sampler\Xray;

class SamplingRule implements \JsonSerializable
{
    public string $RuleName;
    public int $Priority;
    public float $FixedRate;
    public int $ReservoirSize;
    public string $Host;
    public string $HttpMethod;
    public string $ResourceArn;
    public string $ServiceName;
    public string $ServiceType;
    public string $UrlPath;
    public int $Version;
    public array $Attributes;
    
    public function __construct(
        string $ruleName,
        int $priority,
        float $fixedRate,
        int $reservoirSize,
        string $host,
        string $httpMethod,
        string $resourceArn,
        string $serviceName,
        string $serviceType,
        string $urlPath,
        int $version,
        array $attributes = []
    ) {
        $this->RuleName     = $ruleName;
        $this->Priority     = $priority;
        $this->FixedRate    = $fixedRate;
        $this->ReservoirSize= $reservoirSize;
        $this->Host         = $host;
        $this->HttpMethod   = $httpMethod;
        $this->ResourceArn  = $resourceArn;
        $this->ServiceName  = $serviceName;
        $this->ServiceType  = $serviceType;
        $this->UrlPath      = $urlPath;
        $this->Version      = $version;
        $this->Attributes   = $attributes;
    }
    
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
    
    public function compareTo($other): int
    {
        $cmp = $this->Priority <=> $other->Priority;

        return $cmp !== 0 ? $cmp : strcmp($this->RuleName, $other->RuleName);
    }
}
