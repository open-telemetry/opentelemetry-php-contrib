<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Contracts\Queue;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\HookInstance;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue\AttributesBuilder;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\PostHookHandler;
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
                $span = $this->instrumentation
                    ->tracer()
                    ->spanBuilder(vsprintf('%s %s', [
                        method_exists($queue, 'getQueue') ? $queue->getQueue($params[2] ?? null) : $queue->getConnectionName(),
                        TraceAttributeValues::MESSAGING_OPERATION_PUBLISH,
                    ]))
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    ->setAttributes([
                        TraceAttributes::CODE_FUNCTION => $function,
                        TraceAttributes::CODE_NAMESPACE => $class,
                        TraceAttributes::CODE_FILEPATH => $filename,
                        TraceAttributes::CODE_LINENO => $lineno,
                        TraceAttributes::MESSAGING_BATCH_MESSAGE_COUNT => count($params[0] ?? []),
                    ])
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
                $span = $this->instrumentation
                    ->tracer()
                    ->spanBuilder(vsprintf('%s %s', [
                        method_exists($queue, 'getQueue') ? $queue->getQueue($params[2] ?? null) : $queue->getConnectionName(),
                        'create',
                    ]))
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    ->setAttributes([
                        TraceAttributes::CODE_FUNCTION => $function,
                        TraceAttributes::CODE_NAMESPACE => $class,
                        TraceAttributes::CODE_FILEPATH => $filename,
                        TraceAttributes::CODE_LINENO => $lineno,
                        'messaging.message.delivery_timestamp' => match (true) {
                            is_int($params[0]) => (new \DateTime())->add(new \DateInterval("P{$params[0]}S"))->getTimestamp(),
                            $params[0] instanceof \DateInterval => (new \DateTime())->add($params[0])->getTimestamp(),
                            $params[0] instanceof \DateTimeInterface => ($params[0])->getTimestamp(),
                            default => null,
                        },
                    ])
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
                $attributes = $this->buildMessageAttributes($queue, ...$params);

                $parent = Context::getCurrent();
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