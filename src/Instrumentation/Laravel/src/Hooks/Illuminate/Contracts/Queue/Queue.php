<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Contracts\Queue;

use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue\AttributesBuilder;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHookTrait;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\PostHookTrait;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\TraceAttributeValues;
use Throwable;

class Queue implements LaravelHook
{
    use AttributesBuilder;
    use LaravelHookTrait;
    use PostHookTrait;

    public function instrument(): void
    {
        $this->hookBulk();
        $this->hookLater();
        $this->hookPushRaw();
    }

    protected function hookBulk(): bool
    {
        return hook(
            QueueContract::class,
            'bulk',
            pre: function (QueueContract $queue, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                $attributes = array_merge([
                    TraceAttributes::CODE_FUNCTION => $function,
                    TraceAttributes::CODE_NAMESPACE => $class,
                    TraceAttributes::CODE_FILEPATH => $filename,
                    TraceAttributes::CODE_LINENO => $lineno,
                    TraceAttributes::MESSAGING_BATCH_MESSAGE_COUNT => count($params[0] ?? []),
                ], $this->contextualMessageSystemAttributes($queue, []));

                /** @psalm-suppress ArgumentTypeCoercion */
                $span = $this->instrumentation
                    ->tracer()
                    ->spanBuilder(vsprintf('%s %s', [
                        /** @phan-suppress-next-line PhanUndeclaredMethod */
                        method_exists($queue, 'getQueue') ? $queue->getQueue($params[2] ?? null) : $queue->getConnectionName(),
                        TraceAttributeValues::MESSAGING_OPERATION_PUBLISH,
                    ]))
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    ->setAttributes($attributes)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));

                return $params;
            },
            post: function (QueueContract $queue, array $params, $returnValue, ?Throwable $exception) {
                $this->endSpan($exception);
            },
        );
    }

    protected function hookLater(): bool
    {
        return hook(
            QueueContract::class,
            'later',
            pre: function (QueueContract $queue, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                $estimateDeliveryTimestamp = match (true) {
                    is_int($params[0]) => (new \DateTimeImmutable())->add(new DateInterval("PT{$params[0]}S"))->getTimestamp(),
                    $params[0] instanceof DateInterval => (new \DateTimeImmutable())->add($params[0])->getTimestamp(),
                    $params[0] instanceof DateTimeInterface => ($params[0])->getTimestamp(),
                    default => $params[0],
                };

                $attributes = [
                    TraceAttributes::CODE_FUNCTION => $function,
                    TraceAttributes::CODE_NAMESPACE => $class,
                    TraceAttributes::CODE_FILEPATH => $filename,
                    TraceAttributes::CODE_LINENO => $lineno,
                    'messaging.message.delivery_timestamp' => $estimateDeliveryTimestamp,
                ];

                /** @psalm-suppress ArgumentTypeCoercion */
                $span = $this->instrumentation
                    ->tracer()
                    ->spanBuilder(vsprintf('%s %s', [
                        /** @phan-suppress-next-line PhanUndeclaredMethod */
                        method_exists($queue, 'getQueue') ? $queue->getQueue($params[2] ?? null) : $queue->getConnectionName(),
                        'create',
                    ]))
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    ->setAttributes($attributes)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));

                return $params;
            },
            post: function (QueueContract $queue, array $params, $returnValue, ?Throwable $exception) {
                $this->endSpan($exception);
            },
        );
    }

    protected function hookPushRaw(): bool
    {
        return hook(
            QueueContract::class,
            'pushRaw',
            pre: function (QueueContract $queue, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                /** @phan-suppress-next-line PhanParamTooFewUnpack */
                $attributes = $this->buildMessageAttributes($queue, ...$params);

                $parent = Context::getCurrent();
                /** @psalm-suppress ArgumentTypeCoercion */
                $span = $this->instrumentation
                    ->tracer()
                    ->spanBuilder(vsprintf('%s %s', [
                        $attributes[TraceAttributes::MESSAGING_DESTINATION_NAME],
                        TraceAttributeValues::MESSAGING_OPERATION_CREATE,
                    ]))
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    ->setAttributes($attributes)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            post: function (QueueContract $queue, array $params, $returnValue, ?Throwable $exception) {
                $this->endSpan($exception);
            },
        );
    }
}
