<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Foundation\Console;

use Illuminate\Foundation\Console\ServeCommand as FoundationServeCommand;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Hook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelConfiguration;

/**
 * Instrument Laravel's local PHP development server.
 */
class ServeCommand implements Hook
{
    public function instrument(
        HookManagerInterface $hookManager,
        LaravelConfiguration $configuration,
        LoggerInterface $logger,
        MeterInterface $meter,
        TracerInterface $tracer,
    ): void {
        $hookManager->hook(
            FoundationServeCommand::class,
            'handle',
            preHook: static function (FoundationServeCommand $serveCommand, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($tracer) {
                foreach ($_ENV as $key => $value) {
                    if (str_starts_with($key, 'OTEL_') && !in_array($key, FoundationServeCommand::$passthroughVariables)) {
                        FoundationServeCommand::$passthroughVariables[] = $key;
                    }
                }
            },
        );
    }
}
