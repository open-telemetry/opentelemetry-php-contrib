<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure;

use Closure;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationException;

class ConfigurationExceptionCallback
{
    public static function create(string $message): Closure
    {
        return Closure::fromCallable(static function () use ($message) : void {
            throw new ConfigurationException($message);
        });
    }
}
