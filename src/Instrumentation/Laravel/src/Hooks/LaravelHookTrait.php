<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;

trait LaravelHookTrait
{
    private static LaravelHook $instance;

    protected function __construct(
        protected CachedInstrumentation $instrumentation,
    ) {
    }

    abstract public function instrument(): void;

    public static function hook(CachedInstrumentation $instrumentation): LaravelHook
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        if (!isset(self::$instance)) {
            /** @phan-suppress-next-line PhanTypeInstantiateTraitStaticOrSelf,PhanTypeMismatchPropertyReal */
            self::$instance = new self($instrumentation);
            self::$instance->instrument();
        }

        return self::$instance;
    }
}
