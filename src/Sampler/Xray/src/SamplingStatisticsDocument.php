<?php

declare(strict_types=1);
// src/Sampler/AWS/SamplingStatisticsDocument.php

namespace OpenTelemetry\Contrib\Sampler\Xray;

class SamplingStatisticsDocument
{
    public function __construct(
        public string $ClientID,
        public string $RuleName,
        public int $RequestCount,
        public int $SampleCount,
        public int $BorrowCount,
        public float $Timestamp,
    ) {
    }
}
