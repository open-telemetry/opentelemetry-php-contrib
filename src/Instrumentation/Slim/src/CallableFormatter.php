<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Slim;

use Closure;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;

class CallableFormatter
{
    /**
     * @see https://stackoverflow.com/a/68113840/2063413
     * @phan-suppress PhanUndeclaredMethod
     * @psalm-suppress UndefinedMethod
     * @psalm-suppress ArgumentTypeCoercion
     */
    public static function format($callable): string
    {
        if ($callable instanceof Closure || (is_string($callable) && function_exists($callable))) {
            $reflection = new ReflectionFunction($callable);
            $name = $reflection->getShortName();
            if ((PHP_VERSION_ID < 80400 && $name === '{closure}') || (PHP_VERSION_ID >= 80400 && str_contains($name, '{closure:'))) {
                return '{closure}';
            }
            $class = $reflection->getClosureScopeClass()?->getName() ?? '';
            if ($reflection->getClosureScopeClass()?->isAnonymous()) {
                return self::shorten($class) . '@anonymous::' . $name;
            }

            if ($class) {
                return self::shorten($class) . '::' . $name;
            }

            return $name;
        }

        if (is_object($callable) || is_string($callable)) {
            try {
                $reflection = new ReflectionClass($callable);
                $name = $reflection->getShortName();
                if ($name === 'Closure') {
                    return '{closure}';
                }

                return self::shorten($name) . '::__invoke';
            } catch (ReflectionException $e) {
                //continue
            }
        }

        return match (true) {
            is_string($callable) => $callable,
            is_array($callable) && is_object($callable[0]) => self::shorten(get_class($callable[0])) . '::' . $callable[1],
            is_array($callable) => self::shorten($callable[0]) . '::' . $callable[1],
            is_object($callable) => get_class($callable),
            default => '{callable}',
        };
    }

    /**
     * @psalm-suppress PossiblyFalseOperand
     */
    private static function shorten(string $callable): string
    {
        //remove namespace, leaving only class name
        $class = array_slice(explode('\\', $callable), -1, 1)[0];
        if (str_contains($class, '@anonymous')) {
            //remove cruft after anonymous
            return substr($class, 0, strpos($class, '@anonymous') + strlen('@anonymous'));
        }

        return $class;
    }
}
