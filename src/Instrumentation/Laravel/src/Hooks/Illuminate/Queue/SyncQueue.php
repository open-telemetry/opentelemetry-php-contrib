<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue;

use Illuminate\Queue\SyncQueue as LaravelSyncQueue;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Hook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\PostHookTrait;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelConfiguration;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

class SyncQueue implements Hook
{
    use AttributesBuilder;
    use PostHookTrait;

    public function instrument(
        HookManagerInterface $hookManager,
        LaravelConfiguration $configuration,
        LoggerInterface $logger,
        MeterInterface $meter,
        TracerInterface $tracer,
    ): void {
        $this->hookPush($hookManager, $tracer);
    }

    protected function hookPush(HookManagerInterface $hookManager, TracerInterface $tracer): void
    {
        $hookManager->hook(
            LaravelSyncQueue::class,
            'push',
            preHook: function (LaravelSyncQueue $queue, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($tracer) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $span = $tracer
                    ->spanBuilder(vsprintf('%s %s', [
                        $queue->getConnectionName(),
                        'process',
                    ]))
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttributes([
                        TraceAttributes::CODE_FUNCTION => $function,
                        TraceAttributes::CODE_NAMESPACE => $class,
                        TraceAttributes::CODE_FILEPATH => $filename,
                        TraceAttributes::CODE_LINENO => $lineno,
                    ])
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            postHook: function (LaravelSyncQueue $queue, array $params, mixed $returnValue, ?Throwable $exception) {
                $this->endSpan($exception);
            },
        );
    }
}
