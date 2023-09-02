<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel;

use Illuminate\Contracts\Foundation\Application;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\CacheWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\ClientRequestWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\ExceptionWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\LogWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\QueryWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\Watcher;
use function OpenTelemetry\Instrumentation\hook;
use Throwable;

class LaravelInstrumentation
{
    public const NAME = 'laravel';

    public static function registerWatchers(Application $app, Watcher $watcher)
    {
        $watcher->register($app);
    }

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.laravel');

        hook(
            Application::class,
            '__construct',
            post: static function (Application $application, array $params, mixed $returnValue, ?Throwable $exception) use ($instrumentation) {
                self::registerWatchers($application, new CacheWatcher());
                self::registerWatchers($application, new ClientRequestWatcher($instrumentation));
                self::registerWatchers($application, new ExceptionWatcher());
                self::registerWatchers($application, new LogWatcher());
                self::registerWatchers($application, new QueryWatcher($instrumentation));
            },
        );

        ConsoleInstrumentation::register($instrumentation);
        HttpInstrumentation::register($instrumentation);
    }
}
