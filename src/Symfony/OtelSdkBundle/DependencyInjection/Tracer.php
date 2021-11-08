<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection;

interface Tracer
{
    public const DEFAULT_KEY = 'io.opentelemetry.contrib.php';
}
