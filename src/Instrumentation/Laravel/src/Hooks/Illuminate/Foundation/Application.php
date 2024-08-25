<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Foundation;

use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Foundation\Application as FoundationalApplication;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Hook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelConfiguration;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\CacheWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\ClientRequestWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\ExceptionWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\LogWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\QueryWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\Watcher;
use Throwable;

class Application implements Hook
{
    public function instrument(
        HookManagerInterface $hookManager,
        LaravelConfiguration $configuration,
        LoggerInterface $logger,
        MeterInterface $meter,
        TracerInterface $tracer,
    ): void {
        $hookManager->hook(
            FoundationalApplication::class,
            '__construct',
            postHook: function (FoundationalApplication $application, array $params, mixed $returnValue, ?Throwable $exception) use ($tracer) {
                $this->registerWatchers($application, new CacheWatcher());
                $this->registerWatchers($application, new ClientRequestWatcher($tracer));
                $this->registerWatchers($application, new ExceptionWatcher());
                $this->registerWatchers($application, new LogWatcher());
                $this->registerWatchers($application, new QueryWatcher($tracer));
            },
        );
    }

    private function registerWatchers(ApplicationContract $app, Watcher $watcher): void
    {
        $watcher->register($app);
    }
}
