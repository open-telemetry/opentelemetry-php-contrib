<?php

declare(strict_types=1);
// src/Sampler/AWS/Clock.php

namespace OpenTelemetry\Contrib\Sampler\Xray;

class Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }
    
    public function toUnixMillis(\DateTimeImmutable $dt): float
    {
        return $dt->getTimestamp() * 1000;
    }
}
