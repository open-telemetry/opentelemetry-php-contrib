<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Queues;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue\ContextualAttributesBuilders\AbstractContextualAttributesBuilder;
use OpenTelemetry\SemConv\TraceAttributes;

class AnotherAttributesBuilder extends AbstractContextualAttributesBuilder
{
    protected ?string $handleClass = AnotherQueue::class;

    public function contextualAttributes(
        QueueContract $queue,
        array $payload,
        ?string $queueName = null,
        array $options = [],
        ...$params
    ): array {
        return [
            TraceAttributes::MESSAGING_SYSTEM => 'another-queue',
            TraceAttributes::MESSAGING_DESTINATION_NAME =>  $queue->getQueue($queueName),
        ];
    }
}
