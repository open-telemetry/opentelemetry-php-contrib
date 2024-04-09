<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue as AbstractQueue;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Queue\AttributesBuilder;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\TraceAttributeValues;
use Throwable;

class Queue
{
    use AttributesBuilder;
    use HookInstance;
    use PostHookHandler;

    public function instrument(): void
    {
        $this->hookAbstractQueueCreatePayloadArray();
        $this->hookQueuePushRaw();

        Queue\Worker::hook($this->instrumentation);
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

    protected function hookQueuePushRaw(): bool
    {
        return hook(
            QueueContract::class,
            'pushRaw',
            pre: function (QueueContract $queue, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                $attributes = $this->buildMessageAttributes($queue, ...$params);

                $parent = Context::getCurrent();
                $span = $this->instrumentation
                    ->tracer()
                    ->spanBuilder(vsprintf('%s %s', [
                        $attributes[TraceAttributes::MESSAGING_DESTINATION_NAME],
                        TraceAttributeValues::MESSAGING_OPERATION_PUBLISH,
                    ]))
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    ->setAttributes($attributes)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            post: function (QueueContract $queue, array $params, $returnValue, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }

                $this->endSpan($exception);
            },
        );
    }
}
