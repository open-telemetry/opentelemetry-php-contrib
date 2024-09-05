<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use OpenTelemetry\SemConv\TraceAttributes;

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
        foreach (AttributesBuilderRegister::getBuilders() as $builder) {
            if ($builder->canHandle($queue)) {
                return $builder->contextualAttributes($queue, $payload, $queueName, $options, $params);
            }
        }

        return [];
    }
}
