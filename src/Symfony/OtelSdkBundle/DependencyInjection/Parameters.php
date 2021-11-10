<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection;

interface Parameters
{
    public const SERVICE_NAME = 'open_telemetry.sdk.service_name';
    public const DEFAULT_SERVICE_NAME = 'Symfony Application';
    public const SAMPLER_TRACE_ID_RATIO_BASED_DEFAULT_RATIO =
        'open_telemetry.sdk.trace.sampler.trace_id_ratio_based.default_ratio';
    public const DEFAULT_SAMPLER_TRACE_ID_RATIO_BASED_DEFAULT_RATIO = 1.0;
    public const RESOURCE_ATTRIBUTES = 'open_telemetry.sdk.resource.attributes';
    public const DEFAULT_RESOURCE_ATTRIBUTES = [];

    public const DEFAULTS = [
        self::SERVICE_NAME => self::DEFAULT_SERVICE_NAME,
        self::SAMPLER_TRACE_ID_RATIO_BASED_DEFAULT_RATIO => self::DEFAULT_SAMPLER_TRACE_ID_RATIO_BASED_DEFAULT_RATIO,
        self::RESOURCE_ATTRIBUTES => self::DEFAULT_RESOURCE_ATTRIBUTES,
    ];
}
