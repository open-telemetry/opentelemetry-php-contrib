<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Shim\OpenTracing;

use ArrayIterator;
use OpenTelemetry\Context\ContextInterface;
use OpenTracing as API;

class SpanContext implements API\SpanContext
{
    private ContextInterface $context;
    private array $baggageItems = [];

    public function __construct(ContextInterface $context, array $baggage = [])
    {
        $this->context = $context;
        $this->baggageItems = $baggage;
    }

    /**
     * @inheritDoc
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->baggageItems);
    }

    /**
     * @inheritDoc
     */
    public function getBaggageItem(string $key): ?string
    {
        return \array_key_exists($key, $this->baggageItems) ? $this->baggageItems[$key] : null;
    }

    /**
     * @inheritDoc
     */
    public function withBaggageItem(string $key, string $value): API\SpanContext
    {
        return new self($this->context, [$key => $value] + $this->baggageItems);
    }

    public function getContext(): ContextInterface
    {
        return $this->context;
    }
}
