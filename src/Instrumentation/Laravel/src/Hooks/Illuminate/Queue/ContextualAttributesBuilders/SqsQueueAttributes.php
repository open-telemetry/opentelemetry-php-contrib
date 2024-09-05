<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue\ContextualAttributesBuilders;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\SqsQueue;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\TraceAttributeValues;

class SqsQueueAttributes extends AbstractContextualAttributesBuilder
{
    protected ?string $handleClass = SqsQueue::class;

    public function contextualAttributes(
        Queue $queue,
        array $payload,
        ?string $queueName = null,
        array $options = [],
        ...$params
    ): array {
        if (!$queue instanceof SqsQueue) {
            return [];
        }

        return [
            TraceAttributes::MESSAGING_SYSTEM => TraceAttributeValues::MESSAGING_SYSTEM_AWS_SQS,
            TraceAttributes::MESSAGING_DESTINATION_NAME => $queue->getQueue($queueName),
        ];
    }
}
