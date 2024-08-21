<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\BeanstalkdQueue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\SqsQueue;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\TraceAttributeValues;

trait AttributesBuilder
{
    private function buildMessageAttributes(
        QueueContract $queue,
        string $rawPayload,
        ?string $queueName = null,
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
        ?string $queueName = null,
        array $options = [],
        mixed ...$params,
    ): array {
        return match (true) {
            $queue instanceof BeanstalkdQueue => $this->beanstalkContextualAttributes($queue, $payload, $queueName, $options, ...$params),
            $queue instanceof RedisQueue => $this->redisContextualAttributes($queue, $payload, $queueName, $options, ...$params),
            $queue instanceof SqsQueue => $this->awsSqsContextualAttributes($queue, $payload, $queueName, $options, ...$params),
            default => [],
        };
    }

    private function beanstalkContextualAttributes(BeanstalkdQueue $queue, array $payload, ?string $queueName = null, array $options = [], mixed ...$params): array
    {
        return [
            TraceAttributes::MESSAGING_SYSTEM => 'beanstalk',
            TraceAttributes::MESSAGING_DESTINATION_NAME => $queue->getQueue($queueName),
        ];
    }

    private function redisContextualAttributes(RedisQueue $queue, array $payload, ?string $queueName = null, array $options = [], mixed ...$params): array
    {
        return [
            TraceAttributes::MESSAGING_SYSTEM => 'redis',
            TraceAttributes::MESSAGING_DESTINATION_NAME => $queue->getQueue($queueName),
        ];
    }

    private function awsSqsContextualAttributes(SqsQueue $queue, array $payload, ?string $queueName = null, array $options = [], mixed ...$params): array
    {
        return [
            TraceAttributes::MESSAGING_SYSTEM => TraceAttributeValues::MESSAGING_SYSTEM_AWS_SQS,
            TraceAttributes::MESSAGING_DESTINATION_NAME => $queue->getQueue($queueName),
        ];
    }
}
