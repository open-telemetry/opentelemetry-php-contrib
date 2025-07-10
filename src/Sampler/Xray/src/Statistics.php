<?php

declare(strict_types=1);
// src/Sampler/AWS/Statistics.php

namespace OpenTelemetry\Contrib\Sampler\Xray;

class Statistics
{
    public int $requestCount = 0;
    public int $sampleCount  = 0;
    public int $borrowCount  = 0;
}
