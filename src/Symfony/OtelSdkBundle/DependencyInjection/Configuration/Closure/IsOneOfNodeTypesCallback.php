<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure;

use Closure;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationInterface;

class IsOneOfNodeTypesCallback
{
    public static function create(array $types, bool $isType = true): Closure
    {
        return $isType
            ? self::isOneOfTypes($types)
            : self::isNotOneOfTypes($types);
    }

    private static function isOneOfTypes(array $types): Closure
    {
        return Closure::fromCallable(static function (array $config) use ($types) : bool {
            return isset($config[ConfigurationInterface::TYPE_NODE])
                && in_array($config[ConfigurationInterface::TYPE_NODE], $types, true);
        });
    }

    private static function isNotOneOfTypes(array $types): Closure
    {
        return Closure::fromCallable(static function (array $config) use ($types) : bool {
            return !isset($config[ConfigurationInterface::TYPE_NODE])
                || !in_array($config[ConfigurationInterface::TYPE_NODE], $types, true);
        });
    }
}
