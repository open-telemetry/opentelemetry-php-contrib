<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;

class LaravelInstrumentation
{
    public const NAME = 'laravel';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.laravel');

        ConsoleInstrumentation::register($instrumentation);
        HttpInstrumentation::register($instrumentation);

        Hooks\Illuminate\Contracts\Queue\Queue::hook($instrumentation);
        Hooks\Illuminate\Foundation\Application::hook($instrumentation);
        Hooks\Illuminate\Foundation\Console\ServeCommand::hook($instrumentation);
        Hooks\Illuminate\Queue\SyncQueue::hook($instrumentation);
        Hooks\Illuminate\Queue\Queue::hook($instrumentation);
        Hooks\Illuminate\Queue\Worker::hook($instrumentation);
    }
}
