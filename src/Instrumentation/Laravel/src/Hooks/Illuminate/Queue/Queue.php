<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue;

use Illuminate\Queue\Queue as AbstractQueue;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\HookInstance;
use function OpenTelemetry\Instrumentation\hook;
use Throwable;

class Queue
{
    use AttributesBuilder;
    use HookInstance;

    public function instrument(): void
    {
        $this->hookAbstractQueueCreatePayloadArray();
    }

    protected function hookAbstractQueueCreatePayloadArray(): bool
    {
        return hook(
            AbstractQueue::class,
            'createPayloadArray',
            post: function (AbstractQueue $queue, array $params, array $payload, ?Throwable $exception): array {
                TraceContextPropagator::getInstance()->inject($payload);

                return $payload;
            },
        );
    }
}
