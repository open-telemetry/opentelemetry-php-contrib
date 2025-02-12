<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Foundation;

use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Foundation\Application as FoundationalApplication;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHookTrait;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\CacheWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\ClientRequestWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\ExceptionWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\LogWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\QueryWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\RedisCommand\RedisCommandWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\Watcher;
use function OpenTelemetry\Instrumentation\hook;
use Throwable;

class Application implements LaravelHook
{
    use LaravelHookTrait;

    public function instrument(): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        hook(
            FoundationalApplication::class,
            '__construct',
            post: function (FoundationalApplication $application, array $_params, mixed $_returnValue, ?Throwable $_exception) {
                $this->registerWatchers($application, new CacheWatcher());
                $this->registerWatchers($application, new ClientRequestWatcher($this->instrumentation));
                $this->registerWatchers($application, new ExceptionWatcher());
                $this->registerWatchers($application, new LogWatcher($this->instrumentation));
                $this->registerWatchers($application, new QueryWatcher($this->instrumentation));
                $this->registerWatchers($application, new RedisCommandWatcher($this->instrumentation));
            },
        );
    }

    private function registerWatchers(ApplicationContract $app, Watcher $watcher): void
    {
        $watcher->register($app);
    }
}
