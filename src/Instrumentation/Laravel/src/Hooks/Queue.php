<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue as AbstractQueue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\SqsQueue;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

class Queue extends AbstractHook
{
    public function instrument(): void
    {
        $this->hookAbstractQueueCreatePayloadArray();
        $this->hookQueuePushRaw();
    }

    protected function hookAbstractQueueCreatePayloadArray(): bool
    {
        // @todo: remove once post-hook return value works.
        AbstractQueue::createPayloadUsing(function () {
            $carrier = [];
            TraceContextPropagator::getInstance()->inject($carrier);

            return $carrier;
        });

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
                    ->spanBuilder("{$attributes[TraceAttributes::MESSAGING_DESTINATION_NAME]} publish")
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    ->startSpan()
                    ->setAttributes($attributes);

                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            post: function (QueueContract $queue, array $params, $returnValue, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }

                $scope->detach();
                $span = Span::fromContext($scope->context());
                if ($exception) {
                    $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
            },
        );
    }

    private function buildMessageAttributes(
        QueueContract $queue,
        string $rawPayload,
        string $queueName = null,
        array $options = [],
        mixed ...$params,
    ): array {
        $payload = json_decode($rawPayload, true) ?? [];

        return array_merge([
            TraceAttributes::MESSAGING_DESTINATION_NAME => '(anonymous)',
            TraceAttributes::MESSAGING_MESSAGE_ID => $payload['uuid'] ?? $payload['id'] ?? null,
            TraceAttributes::MESSAGING_MESSAGE_ENVELOPE_SIZE => strlen($rawPayload),
            'messaging.message.job_name' => $payload['displayName'] ?? $payload['job'] ?? null,
            'messaging.message.attempts' => $payload['attempts'] ?? 0,
            'messaging.message.max_exceptions' => $payload['maxExceptions'] ?? null,
            'messaging.message.max_tries' => $payload['maxTries'] ?? null,
            'messaging.message.retry_until' => $payload['retryUntil'] ?? null,
            'messaging.message.timeout' => $payload['timeout'] ?? null,
        ], $this->contextualMessageSystemAttributes($queue, $payload, $queueName, $options, ...$params));
    }

    private function contextualMessageSystemAttributes(
        QueueContract $queue,
        array $payload,
        string $queueName = null,
        array $options = [],
        mixed ...$params,
    ): array {
        return match (true) {
            $queue instanceof RedisQueue => $this->redisContextualAttributes($queue, $payload, $queueName, $options, ...$params),
            $queue instanceof SqsQueue => $this->awsSqsContextualAttributes($queue, $payload, $queueName, $options, ...$params),
            default => [],
        };
    }

    private function redisContextualAttributes(RedisQueue $queue, array $payload, string $queueName = null, array $options = [], mixed ...$params): array
    {
        return [
            TraceAttributes::MESSAGING_SYSTEM => 'redis',
            TraceAttributes::MESSAGING_DESTINATION_NAME => $queue->getQueue($queueName),
        ];
    }

    private function awsSqsContextualAttributes(SqsQueue $queue, array $payload, string $queueName = null, array $options = [], mixed ...$params): array
    {
        return [
            TraceAttributes::MESSAGING_SYSTEM => 'aws_sqs',
            TraceAttributes::MESSAGING_DESTINATION_NAME => $queue->getQueue($queueName),
        ];
    }
}
