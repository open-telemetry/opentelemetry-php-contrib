<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Foundation\Console;

use Illuminate\Foundation\Console\ServeCommand as FoundationServeCommand;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHookTrait;
use function OpenTelemetry\Instrumentation\hook;

/**
 * Instrument Laravel's local PHP development server.
 */
class ServeCommand implements LaravelHook
{
    use LaravelHookTrait;

    public function instrument(): void
    {
        hook(
            FoundationServeCommand::class,
            'handle',
            pre: static function (FoundationServeCommand $serveCommand, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                if (!property_exists(FoundationServeCommand::class, 'passthroughVariables')) {
                    return;
                }

                foreach ($_ENV as $key => $value) {
                    if (str_starts_with($key, 'OTEL_') && !in_array($key, FoundationServeCommand::$passthroughVariables)) {
                        FoundationServeCommand::$passthroughVariables[] = $key;
                    }
                }
            },
        );
    }
}
