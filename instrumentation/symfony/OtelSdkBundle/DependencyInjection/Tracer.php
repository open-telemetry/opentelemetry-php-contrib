<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\Symfony\OtelSdkBundle\DependencyInjection;

interface Tracer
{
    public const DEFAULT_KEY = 'io.opentelemetry.contrib.php';
}
