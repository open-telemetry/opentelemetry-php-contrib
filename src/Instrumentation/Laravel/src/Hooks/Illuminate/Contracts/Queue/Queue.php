<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Contracts\Queue;

use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Context as InstrumentationContext;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Hook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue\AttributesBuilder;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\PostHookTrait;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelInstrumentation;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\TraceAttributeValues;
use OpenTelemetry\SemConv\Version;
use Throwable;

class Queue implements Hook
{
    use AttributesBuilder;
    use PostHookTrait;

    public function instrument(
        LaravelInstrumentation $instrumentation,
        HookManagerInterface $hookManager,
        InstrumentationContext $context,
    ): void {
        $tracer = $context->tracerProvider->getTracer(
            $instrumentation->buildProviderName('queue'),
            schemaUrl: Version::VERSION_1_24_0->url(),
        );

        $this->hookBulk($hookManager, $tracer);
        $this->hookLater($hookManager, $tracer);
        $this->hookPushRaw($hookManager, $tracer);
    }

    protected function hookBulk(HookManagerInterface $hookManager, TracerInterface $tracer): void
    {
        $hookManager->hook(
            QueueContract::class,
            'bulk',
            preHook: function (QueueContract $queue, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($tracer) {
                $attributes = array_merge([
                    TraceAttributes::CODE_FUNCTION => $function,
                    TraceAttributes::CODE_NAMESPACE => $class,
                    TraceAttributes::CODE_FILEPATH => $filename,
                    TraceAttributes::CODE_LINENO => $lineno,
                    TraceAttributes::MESSAGING_BATCH_MESSAGE_COUNT => count($params[0] ?? []),
                ], $this->contextualMessageSystemAttributes($queue, []));

                /** @psalm-suppress ArgumentTypeCoercion */
                $span = $tracer
                    ->spanBuilder(vsprintf('%s %s', [
                        /** @phan-suppress-next-line PhanUndeclaredMethod */
                        method_exists($queue, 'getQueue') ? $queue->getQueue($params[2] ?? null) : $queue->getConnectionName(),
                        TraceAttributeValues::MESSAGING_OPERATION_TYPE_PUBLISH,
                    ]))
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    ->setAttributes($attributes)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));

                return $params;
            },
            postHook: function (QueueContract $queue, array $params, $returnValue, ?Throwable $exception) {
                $this->endSpan($exception);
            },
        );
    }

    protected function hookLater(HookManagerInterface $hookManager, TracerInterface $tracer): void
    {
        $hookManager->hook(
            QueueContract::class,
            'later',
            preHook: function (QueueContract $queue, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($tracer) {
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
                $span = $tracer
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
            postHook: function (QueueContract $queue, array $params, $returnValue, ?Throwable $exception) {
                $this->endSpan($exception);
            },
        );
    }

    protected function hookPushRaw(HookManagerInterface $hookManager, TracerInterface $tracer): void
    {
        $hookManager->hook(
            QueueContract::class,
            'pushRaw',
            preHook: function (QueueContract $queue, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($tracer) {
                /** @phan-suppress-next-line PhanParamTooFewUnpack */
                $attributes = $this->buildMessageAttributes($queue, ...$params);

                $parent = Context::getCurrent();
                /** @psalm-suppress ArgumentTypeCoercion */
                $span = $tracer
                    ->spanBuilder(vsprintf('%s %s', [
                        $attributes[TraceAttributes::MESSAGING_DESTINATION_NAME],
                        TraceAttributeValues::MESSAGING_OPERATION_TYPE_CREATE,
                    ]))
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    ->setAttributes($attributes)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            postHook: function (QueueContract $queue, array $params, $returnValue, ?Throwable $exception) {
                $this->endSpan($exception);
            },
        );
    }
}
