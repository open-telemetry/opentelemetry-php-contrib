<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue;

use Illuminate\Contracts\Queue\Queue as QueueContract;

interface ContextualAttributesBuilder
{
    public function canHandle(QueueContract $queue): bool;

    public function contextualAttributes(
        QueueContract $queue,
        array $payload,
        ?string $queueName = null,
        array $options = [],
        mixed ...$params
    ): array;
}
