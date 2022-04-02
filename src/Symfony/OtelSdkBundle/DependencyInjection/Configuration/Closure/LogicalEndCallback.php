<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure;

use Closure;
use InvalidArgumentException;
use ReflectionException;
use ReflectionFunction;
use ReflectionNamedType;

class LogicalEndCallback
{
    private const TYPE_BOOL = 'bool';
    private const TYPE_UNKNOWN = 'unknown';

    public static function create(Closure ...$closure): Closure
    {
        self::validate(...$closure);

        return Closure::fromCallable(static function (array $config) use ($closure) : bool {
            $result = true;

            foreach ($closure as $call) {
                $result = $result && $call($config);
            }

            return $result;
        });
    }

    /**
     * @throws ReflectionException
     */
    private static function validate(Closure ...$closure): void
    {
        foreach ($closure as $call) {
            $returnType = (new ReflectionFunction($call))->getReturnType();

            if (!$returnType instanceof ReflectionNamedType || $returnType->getName() !== self::TYPE_BOOL) {
                throw new InvalidArgumentException(
                    sprintf(
                        'All provided closures must have boolean return type. Give: "%s"',
                        $returnType instanceof ReflectionNamedType ? $returnType->getName() : self::TYPE_UNKNOWN
                    )
                );
            }
        }
    }
}
