<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure;

use Closure;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Normalizer\ExporterConfigNormalizer;

class ExporterConfigNormalizerCallback
{
    public static function create(): Closure
    {
        return Closure::fromCallable(static function ($config): array {
            return ExporterConfigNormalizer::normalize($config);
        });
    }
}
