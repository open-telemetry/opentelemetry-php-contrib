<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Foundation\Console;

use Illuminate\Foundation\Console\ServeCommand as FoundationServeCommand;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Context as InstrumentationContext;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Hook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelConfiguration;

/**
 * Instrument Laravel's local PHP development server.
 *
 * @psalm-suppress UnusedClass
 */
class ServeCommand implements Hook
{
    public function instrument(
        LaravelConfiguration $configuration,
        HookManagerInterface $hookManager,
        InstrumentationContext $context,
    ): void {
        $hookManager->hook(
            FoundationServeCommand::class,
            'handle',
            preHook: static function (FoundationServeCommand $_serveCommand, array $_params, string $_class, string $_function, ?string $_filename, ?int $_lineno) {
                foreach ($_ENV as $key => $_value) {
                    if (str_starts_with($key, 'OTEL_') && !in_array($key, FoundationServeCommand::$passthroughVariables)) {
                        FoundationServeCommand::$passthroughVariables[] = $key;
                    }
                }
            },
        );
    }
}
