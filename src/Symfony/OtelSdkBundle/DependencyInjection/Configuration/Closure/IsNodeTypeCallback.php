<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure;

use Closure;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationInterface;

class IsNodeTypeCallback
{
    public static function create(string $type, bool $isType = true): Closure
    {
        return $isType
            ? self::isType($type)
            : self::isNotType($type);
    }

    private static function isType(string $type): Closure
    {
        return Closure::fromCallable(static function (array $config) use ($type) : bool {
            return isset($config[ConfigurationInterface::TYPE_NODE])
                && $config[ConfigurationInterface::TYPE_NODE] === $type;
        });
    }

    private static function isNotType(string $type): Closure
    {
        return Closure::fromCallable(static function (array $config) use ($type) : bool {
            return !isset($config[ConfigurationInterface::TYPE_NODE])
            || $config[ConfigurationInterface::TYPE_NODE] !== $type;
        });
    }
}
