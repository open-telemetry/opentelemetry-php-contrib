<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue\ContextualAttributesBuilders;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\BeanstalkdQueue;
use OpenTelemetry\SemConv\TraceAttributes;

class BeanstalkdQueueAttributes extends AbstractContextualAttributesBuilder
{
    protected ?string $handleClass = BeanstalkdQueue::class;

    public function contextualAttributes(
        Queue $queue,
        array $payload,
        ?string $queueName = null,
        array $options = [],
        ...$params
    ): array {
        if (!$queue instanceof BeanstalkdQueue) {
            return [];
        }

        return [
            TraceAttributes::MESSAGING_SYSTEM => 'beanstalk',
            TraceAttributes::MESSAGING_DESTINATION_NAME => $queue->getQueue($queueName),
        ];
    }
}
