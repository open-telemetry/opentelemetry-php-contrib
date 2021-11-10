<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\Util;

use Symfony\Component\DependencyInjection\Reference;

class ConfigHelper
{
    /**
     * @param string $parameter
     * @return string
     */
    public static function wrapParameter(string $parameter): string
    {
        return '%' . $parameter . '%';
    }

    /**
     * @param string $id
     * @return Reference
     */
    public static function createReference(string $id): Reference
    {
        return new Reference($id);
    }

    /**
     * @param string $class
     * @psalm-param class-string $class
     * @return Reference
     */
    public static function createReferenceFromClass(string $class): Reference
    {
        return self::createReference(ServiceHelper::classToId($class));
    }
}
