<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel;

use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelInstrumentation;

/**
 * Prevent registering too many hooks.
 */
class InstrumentationHelper
{
    public static function instance(): LaravelInstrumentation
    {
        static $instance;
        if ($instance === null) {
            $instance = new LaravelInstrumentation();
            $instance->activate();
        }

        return $instance;
    }
}
