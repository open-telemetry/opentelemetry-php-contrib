<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\ServeCommand;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\CacheWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\ClientRequestWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\ExceptionWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\LogWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\QueryWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\RequestWatcher;
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
                self::registerWatchers($application, new RequestWatcher());
            },
        );

        ConsoleInstrumentation::register($instrumentation);
        HttpInstrumentation::register($instrumentation);

        self::developmentInstrumentation();
    }

    private static function developmentInstrumentation(): void
    {
        // Allow instrumentation when using the local PHP development server.
        if (class_exists(ServeCommand::class) && property_exists(ServeCommand::class, 'passthroughVariables')) {
            hook(
                ServeCommand::class,
                'handle',
                pre: static function (ServeCommand $serveCommand, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                    foreach ($_ENV as $key => $value) {
                        if (str_starts_with($key, 'OTEL_') && !in_array($key, ServeCommand::$passthroughVariables)) {
                            ServeCommand::$passthroughVariables[] = $key;
                        }
                    }
                },
            );
        }
    }
}
