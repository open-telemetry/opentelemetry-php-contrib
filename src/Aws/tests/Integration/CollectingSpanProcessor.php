<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Aws\Integration;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;

class CollectingSpanProcessor implements SpanProcessorInterface
{
    private array $collectedSpans = [];

    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
    {
        $this->collectedSpans[$span->getContext()->getSpanId()] = $span;
    }

    public function onEnd(ReadableSpanInterface $span): void
    {
        $this->collectedSpans[$span->getContext()->getSpanId()] = $span;
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    public function getCollectedSpans(): array
    {
        return $this->collectedSpans;
    }
}
