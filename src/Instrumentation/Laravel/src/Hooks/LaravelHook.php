<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;

interface LaravelHook
{
    public static function hook(CachedInstrumentation $instrumentation): LaravelHook;

    public function instrument(): void;
}
