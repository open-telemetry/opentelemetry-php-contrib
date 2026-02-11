<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\SDK\Common\Configuration\Configuration;

class LaravelInstrumentation
{
    public const NAME = 'laravel';

    /** @psalm-suppress PossiblyUnusedMethod */
    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.laravel',
            null,
            'https://opentelemetry.io/schemas/1.32.0',
        );

        $config = InstrumentationConfig::getInstance();

        if ($config->isInstrumentationEnabled(InstrumentationConfig::HTTP)) {
            Hooks\Illuminate\Contracts\Http\Kernel::hook($instrumentation);
        }

        if ($config->isInstrumentationEnabled(InstrumentationConfig::CONSOLE)) {
            Hooks\Illuminate\Console\Command::hook($instrumentation);
            Hooks\Illuminate\Contracts\Console\Kernel::hook($instrumentation);
        }

        if ($config->isInstrumentationEnabled(InstrumentationConfig::QUEUE)) {
            Hooks\Illuminate\Contracts\Queue\Queue::hook($instrumentation);
            Hooks\Illuminate\Queue\SyncQueue::hook($instrumentation);
            Hooks\Illuminate\Queue\Queue::hook($instrumentation);
            Hooks\Illuminate\Queue\Worker::hook($instrumentation);
        }

        if ($config->isInstrumentationEnabled(InstrumentationConfig::ELOQUENT)) {
            Hooks\Illuminate\Database\Eloquent\Model::hook($instrumentation);
        }

        if ($config->isInstrumentationEnabled(InstrumentationConfig::SERVE)) {
            Hooks\Illuminate\Foundation\Console\ServeCommand::hook($instrumentation);
        }

        if ($config->hasAnyWatcherEnabled()) {
            Hooks\Illuminate\Foundation\Application::hook($instrumentation);
        }
    }

    public static function shouldTraceCli(): bool
    {
        return PHP_SAPI !== 'cli' || (
            class_exists(Configuration::class)
            && Configuration::getBoolean('OTEL_PHP_TRACE_CLI_ENABLED', false)
        );
    }
}
