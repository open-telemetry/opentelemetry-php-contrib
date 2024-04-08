<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;

trait HookInstance
{
    private static ?self $instance = null;

    protected function __construct(
        protected CachedInstrumentation $instrumentation,
    ) {
    }

    abstract public function instrument(): void;

    public static function hook(CachedInstrumentation $instrumentation): self
    {
        if (self::$instance === null) {
            self::$instance = new self($instrumentation);
            self::$instance->instrument();
        }

        return self::$instance;
    }
}
