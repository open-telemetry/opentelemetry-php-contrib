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
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\MessagingIncubatingAttributes;
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

    /** @psalm-suppress PossiblyUnusedReturnValue  */
    protected function hookBulk(): bool
    {
        return hook(
            QueueContract::class,
            'bulk',
            pre: function (QueueContract $queue, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                $attributes = array_merge([
                    CodeAttributes::CODE_FUNCTION_NAME => sprintf('%s::%s', $class, $function),
                    CodeAttributes::CODE_FILE_PATH => $filename,
                    CodeAttributes::CODE_LINE_NUMBER => $lineno,
                    MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE => MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE_VALUE_SEND,
                    MessagingIncubatingAttributes::MESSAGING_BATCH_MESSAGE_COUNT => count($params[0] ?? []),
                ], $this->contextualMessageSystemAttributes($queue, []));

                /** @psalm-suppress ArgumentTypeCoercion */
                $span = $this->instrumentation
                    ->tracer()
                    ->spanBuilder(vsprintf('%s %s', [
                        MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE_VALUE_SEND,
                        /** @phan-suppress-next-line PhanUndeclaredMethod */
                        method_exists($queue, 'getQueue') ? $queue->getQueue($params[2] ?? null) : $queue->getConnectionName(),
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

    /** @psalm-suppress PossiblyUnusedReturnValue  */
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
                    CodeAttributes::CODE_FUNCTION_NAME => sprintf('%s::%s', $class, $function),
                    CodeAttributes::CODE_FILE_PATH => $filename,
                    CodeAttributes::CODE_LINE_NUMBER => $lineno,
                    MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE => MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE_VALUE_CREATE,
                    'messaging.message.delivery_timestamp' => $estimateDeliveryTimestamp,
                ];

                /** @psalm-suppress ArgumentTypeCoercion */
                $span = $this->instrumentation
                    ->tracer()
                    ->spanBuilder(vsprintf('%s %s', [
                        MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE_VALUE_CREATE,
                        /** @phan-suppress-next-line PhanUndeclaredMethod */
                        method_exists($queue, 'getQueue') ? $queue->getQueue($params[3] ?? null) : $queue->getConnectionName(),
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

    /** @psalm-suppress PossiblyUnusedReturnValue  */
    protected function hookPushRaw(): bool
    {
        return hook(
            QueueContract::class,
            'pushRaw',
            pre: function (QueueContract $queue, array $params, string $_class, string $_function, ?string $_filename, ?int $_lineno) {
                /** @phan-suppress-next-line PhanParamTooFewUnpack */
                $attributes = $this->buildMessageAttributes($queue, ...$params);
                $attributes[MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE] = MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE_VALUE_CREATE;

                $parent = Context::getCurrent();
                /** @psalm-suppress ArgumentTypeCoercion */
                $span = $this->instrumentation
                    ->tracer()
                    ->spanBuilder(vsprintf('%s %s', [
                        MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE_VALUE_CREATE,
                        $attributes[MessagingIncubatingAttributes::MESSAGING_DESTINATION_NAME],
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
