<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue;

use Illuminate\Queue\Queue as AbstractQueue;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Hook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelConfiguration;
use Throwable;

class Queue implements Hook
{
    use AttributesBuilder;

    public function instrument(
        HookManagerInterface $hookManager,
        LaravelConfiguration $configuration,
        LoggerInterface $logger,
        MeterInterface $meter,
        TracerInterface $tracer,
    ): void {
        $hookManager->hook(
            AbstractQueue::class,
            'createPayloadArray',
            postHook: function (AbstractQueue $queue, array $params, array $payload, ?Throwable $exception): array {
                TraceContextPropagator::getInstance()->inject($payload);

                return $payload;
            },
        );
    }
}
