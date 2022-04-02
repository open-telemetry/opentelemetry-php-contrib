<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Validator;

use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationException;
use ReflectionClass;

class CustomClassImplementationValidator
{
    public static function approve(string $className, string $interfaceName, string $type = ''): void
    {
        if (!self::classImplements($className, $interfaceName)) {
            throw new ConfigurationException(
                sprintf(
                    'Custom %s class needs to implement %s',
                    $type,
                    SpanExporterInterface::class
                )
            );
        }
    }

    private static function classImplements(string $className, string $interfaceName): bool
    {
        try {
            return in_array($interfaceName, (new ReflectionClass($className))->getInterfaceNames());
        } catch (\Throwable $t) {
            return false;
        }
    }
}
