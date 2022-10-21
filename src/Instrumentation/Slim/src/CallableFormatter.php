<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Slim;

class CallableFormatter
{
    /**
     * @see https://stackoverflow.com/a/68113840/2063413
     */
    public static function format($callable): string
    {
        return match (true) {
            is_string($callable) => $callable,
            is_array($callable) && is_object($callable[0]) => get_class($callable[0]) . '->' . $callable[1],
            is_array($callable) => $callable[0] . '::' . $callable[1],
            $callable instanceof Closure => 'closure', //todo Should use reflection - ::format($callable) and ::format($callable(...)) should return the same result.
            is_object($callable) => get_class($callable),
            default => 'callable',
        };
    }
}
