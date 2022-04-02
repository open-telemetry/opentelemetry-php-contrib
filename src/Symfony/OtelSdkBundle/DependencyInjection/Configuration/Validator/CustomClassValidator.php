<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Validator;

use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationException;

class CustomClassValidator
{
    public static function approve(string $className, string $type = ''): void
    {
        if (!class_exists($className)) {
            throw new ConfigurationException(
                sprintf(
                    'Could not find custom %s class. given: %s',
                    $type,
                    $className
                )
            );
        }
    }
}
