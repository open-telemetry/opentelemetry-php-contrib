<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue\ContextualAttributesBuilders;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use InvalidArgumentException;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue\ContextualAttributesBuilder;

abstract class AbstractContextualAttributesBuilder implements ContextualAttributesBuilder
{
    protected ?string $handleClass = null;

    public function canHandle(QueueContract $queue): bool
    {
        if ($this->handleClass === null) {
            throw new InvalidArgumentException('ContextualAttributesBuilder cannot handle an null class.');
        }

        return $queue instanceof $this->handleClass;
    }
}
