<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure;

use Closure;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationException;

class MissingAttributesCallback
{
    public static function createCheck(array $requiredAttributes): Closure
    {
        return Closure::fromCallable(static function (?array $config = []) use ($requiredAttributes) : bool {
            $config = $config ?? [];
            foreach ($requiredAttributes as $attr) {
                if (!isset($config[$attr])) {
                    return true;
                }
            }

            return false;
        });
    }

    public static function createExceptionTrigger(array $requiredAttributes): Closure
    {
        return Closure::fromCallable(static function () use ($requiredAttributes) : bool {
            throw new ConfigurationException(
                sprintf(
                    'OpenTelemetry configuration must provide following resource attributes:  %s ',
                    implode(', ', $requiredAttributes)
                )
            );
        });
    }
}
