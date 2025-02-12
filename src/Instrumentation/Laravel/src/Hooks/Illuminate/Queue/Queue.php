<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue;

use Illuminate\Queue\Queue as AbstractQueue;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHookTrait;
use function OpenTelemetry\Instrumentation\hook;
use Throwable;

class Queue implements LaravelHook
{
    use AttributesBuilder;
    use LaravelHookTrait;

    public function instrument(): void
    {
        $this->hookAbstractQueueCreatePayloadArray();
    }

    /** @psalm-suppress PossiblyUnusedReturnValue */
    protected function hookAbstractQueueCreatePayloadArray(): bool
    {
        return hook(
            AbstractQueue::class,
            'createPayloadArray',
            post: function (AbstractQueue $_queue, array $_params, array $payload, ?Throwable $_exception): array {
                TraceContextPropagator::getInstance()->inject($payload);

                return $payload;
            },
        );
    }
}
