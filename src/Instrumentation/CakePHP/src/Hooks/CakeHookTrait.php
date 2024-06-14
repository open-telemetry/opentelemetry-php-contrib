<?php

namespace OpenTelemetry\Contrib\Instrumentation\CakePHP\Hooks;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;

trait CakeHookTrait
{
    private static CakeHook $instance;

    protected function __construct(
        protected CachedInstrumentation $instrumentation,
    ) {
    }

    abstract public function instrument(): void;

    public static function hook(CachedInstrumentation $instrumentation): CakeHook
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