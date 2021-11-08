<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection;

use OpenTelemetry\SDK\Trace\Sampler;

interface Samplers
{
    public const ALWAYS_ON = Sampler\AlwaysOnSampler::class;
    public const ALWAYS_OFF = Sampler\AlwaysOffSampler::class;
    public const PARENT_BASED = Sampler\ParentBased::class;
    public const TRACE_ID_RATIO_BASED = Sampler\TraceIdRatioBasedSampler::class;
    public const SAMPLERS = [
        self::ALWAYS_ON,
        self::ALWAYS_OFF,
        self::PARENT_BASED,
        self::TRACE_ID_RATIO_BASED,
    ];
    public const DEFAULT_SAMPLER = self::ALWAYS_ON;
}
