<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;

abstract class AbstractHook
{
    private static ?self $instance = null;

    protected function __construct(
        protected CachedInstrumentation $instrumentation,
    ) {
    }

    abstract public function instrument(): void;

    public static function hook(CachedInstrumentation $instrumentation): static
    {
        if (static::$instance === null) {
            static::$instance = new static($instrumentation);
            static::$instance->instrument();
        }

        return static::$instance;
    }
}
