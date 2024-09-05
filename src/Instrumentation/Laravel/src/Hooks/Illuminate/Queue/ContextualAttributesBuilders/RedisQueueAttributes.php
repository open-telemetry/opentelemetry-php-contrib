<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue\ContextualAttributesBuilders;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\RedisQueue;
use OpenTelemetry\SemConv\TraceAttributes;

class RedisQueueAttributes extends AbstractContextualAttributesBuilder
{
    protected ?string $handleClass = RedisQueue::class;

    public function contextualAttributes(
        Queue $queue,
        array $payload,
        ?string $queueName = null,
        array $options = [],
        ...$params
    ): array {
        if (!$queue instanceof RedisQueue) {
            return [];
        }

        return [
            TraceAttributes::MESSAGING_SYSTEM => 'redis',
            TraceAttributes::MESSAGING_DESTINATION_NAME => $queue->getQueue($queueName),
        ];
    }
}
