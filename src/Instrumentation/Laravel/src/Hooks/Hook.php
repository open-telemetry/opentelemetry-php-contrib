<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks;

use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelConfiguration;

interface Hook
{
    public function instrument(
        HookManagerInterface $hookManager,
        LaravelConfiguration $configuration,
        LoggerInterface $logger,
        MeterInterface $meter,
        TracerInterface $tracer,
    ): void;
}
