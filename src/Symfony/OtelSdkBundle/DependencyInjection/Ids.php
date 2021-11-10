<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection;

interface Ids
{
    public const SPAN_PROCESSOR_NAMESPACE = 'open_telemetry.sdk.trace.span_processor';
    public const SPAN_PROCESSOR_DEFAULT = self::SPAN_PROCESSOR_NAMESPACE . '.default';
}
