<?php

namespace OpenTelemetry\Contrib\Instrumentation;

class CallableFormatter
{
    /**
     * @see https://stackoverflow.com/a/68113840/2063413
     */
    static function format(callable $callable): string
    {
        return match (true) {
            is_string($callable) => $callable,
            is_array($callable) && is_object($callable[0]) => get_class($callable[0]) . '->' . $callable[1],
            is_array($callable) => $callable[0] . '::' . $callable[1],
            $callable instanceof Closure => 'closure',
            is_object($callable) => get_class($callable),
            default => 'callable',
        };
    }
}