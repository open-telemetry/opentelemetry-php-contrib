<?php

declare(strict_types=1);
// src/Sampler/AWS/SamplingRule.php

namespace OpenTelemetry\Contrib\Sampler\Xray;

/** @psalm-suppress PossiblyUnusedProperty */
class SamplingRule implements \JsonSerializable
{
    public function __construct(
        public string $RuleName,
        public int $Priority,
        public float $FixedRate,
        public int $ReservoirSize,
        public string $Host,
        public string $HttpMethod,
        public string $ResourceArn,
        public string $ServiceName,
        public string $ServiceType,
        public string $UrlPath,
        public int $Version,
        public array $Attributes,
    ) {
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
