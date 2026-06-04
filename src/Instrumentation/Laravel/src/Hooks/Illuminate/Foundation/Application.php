<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Foundation;

use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Foundation\Application as FoundationalApplication;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHookTrait;
use OpenTelemetry\Contrib\Instrumentation\Laravel\InstrumentationConfig;
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
        $config = InstrumentationConfig::getInstance();

        /** @psalm-suppress UnusedFunctionCall */
        hook(
            FoundationalApplication::class,
            '__construct',
            post: function (FoundationalApplication $application, array $_params, mixed $_returnValue, ?Throwable $_exception) use ($config) {
                if ($config->isInstrumentationEnabled(InstrumentationConfig::CACHE)) {
                    $this->registerWatchers($application, new CacheWatcher());
                }
                if ($config->isInstrumentationEnabled(InstrumentationConfig::HTTP_CLIENT)) {
                    $this->registerWatchers($application, new ClientRequestWatcher($this->instrumentation));
                }
                if ($config->isInstrumentationEnabled(InstrumentationConfig::EXCEPTION)) {
                    $this->registerWatchers($application, new ExceptionWatcher());
                }
                if ($config->isInstrumentationEnabled(InstrumentationConfig::LOG)) {
                    $this->registerWatchers($application, new LogWatcher($this->instrumentation));
                }
                if ($config->isInstrumentationEnabled(InstrumentationConfig::DB)) {
                    $this->registerWatchers($application, new QueryWatcher($this->instrumentation));
                }
                if ($config->isInstrumentationEnabled(InstrumentationConfig::REDIS)) {
                    $this->registerWatchers($application, new RedisCommandWatcher($this->instrumentation));
                }
            },
        );
    }

    private function registerWatchers(ApplicationContract $app, Watcher $watcher): void
    {
        $watcher->register($app);
    }
}
