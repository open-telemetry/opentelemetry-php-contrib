<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Foundation;

use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Foundation\Application as FoundationalApplication;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Context as InstrumentationContext;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Hook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelConfiguration;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\CacheWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\ClientRequestWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\ExceptionWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\LogWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\QueryWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\RedisCommand\RedisCommandWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\Watcher;
use Throwable;

/** @psalm-suppress UnusedClass */
class Application implements Hook
{
    public function instrument(
        LaravelConfiguration $configuration,
        HookManagerInterface $hookManager,
        InstrumentationContext $context,
    ): void {
        $hookManager->hook(
            FoundationalApplication::class,
            '__construct',
            postHook: function (FoundationalApplication $application, array $_params, mixed $_returnValue, ?Throwable $_exception) use ($context) {
                $this->registerWatchers($application, new CacheWatcher());
                $this->registerWatchers($application, new ClientRequestWatcher($context));
                $this->registerWatchers($application, new ExceptionWatcher());
                $this->registerWatchers($application, new LogWatcher($context));
                $this->registerWatchers($application, new QueryWatcher($context));
                $this->registerWatchers($application, new RedisCommandWatcher($context));
            },
        );
    }

    private function registerWatchers(ApplicationContract $app, Watcher $watcher): void
    {
        $watcher->register($app);
    }
}
