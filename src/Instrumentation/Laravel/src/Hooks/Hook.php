<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks;

use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Context as InstrumentationContext;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelInstrumentation;

interface Hook
{
    public function instrument(
        LaravelInstrumentation $instrumentation,
        HookManagerInterface $hookManager,
        InstrumentationContext $context,
    ): void;
}
