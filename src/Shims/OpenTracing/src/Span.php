<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Shim\OpenTracing;

use InvalidArgumentException;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ContextInterface;
use OpenTracing as API;

/**
 * @psalm-suppress ArgumentTypeCoercion
 */
class Span implements API\Span
{
    private SpanInterface $span;
    private API\SpanContext $spanContext;
    private string $operationName;

    public function __construct(SpanInterface $span, ContextInterface $context, string $operationName)
    {
        $this->span = $span;
        $this->spanContext = new SpanContext($context);
        $this->operationName = $operationName;
    }

    /**
     * @inheritDoc
     */
    public function getOperationName(): string
    {
        return $this->operationName;
    }

    /**
     * @inheritDoc
     */
    public function getContext(): API\SpanContext
    {
        return $this->spanContext;
    }

    /**
     * @inheritDoc
     */
    public function finish($finishTime = null): void
    {
        $this->span->end();
    }

    /**
     * @inheritDoc
     */
    public function overwriteOperationName(string $newOperationName): void
    {
        $this->span->updateName($newOperationName);
        $this->operationName = $newOperationName;
    }

    /**
     * @inheritDoc
     */
    public function setTag(string $key, $value): void
    {
        if ($value === (bool) $value) {
            $value = $value ? 'true' : 'false';
        }

        if ($key === API\Tags\SPAN_KIND) {
            //warning: OTEL only accepts this at span creation
        }
        if ($key === API\Tags\ERROR) {
            $this->span->setStatus($this->mapErrorToStatusCode($value));
        }
        $this->span->setAttribute($key, $value);
    }

    /**
     * @inheritDoc
     */
    public function log(array $fields = [], $timestamp = null): void
    {
        if ($timestamp instanceof \DateTimeInterface) {
            $timestamp = $timestamp->format('Uu');
        } elseif (!is_float($timestamp) && !is_int($timestamp) && null !== $timestamp) {
            /** @psalm-suppress NoValue */
            throw new InvalidArgumentException(
                sprintf('Invalid timestamp. Expected float, int or DateTime, got %s', $timestamp)
            );
        }
        if (array_key_exists('exception', $fields)) {
            $e = $fields['exception'];
            if ($e instanceof \Throwable) {
                $this->span->recordException($e);
            } elseif (is_string($e)) {
                $this->span->recordException(new \Exception($e));
            } else {
                throw new InvalidArgumentException('exception tag must be Throwable or string');
            }

            return;
        }
        $name = array_key_exists('event', $fields) ? $fields['event'] : 'log';
        $this->span->addEvent($name, $fields, (int) $timestamp);
    }

    /**
     * @inheritDoc
     */
    public function addBaggageItem(string $key, string $value): void
    {
        $this->spanContext = $this->spanContext->withBaggageItem($key, $value);
    }

    /**
     * @inheritDoc
     */
    public function getBaggageItem(string $key): ?string
    {
        return $this->spanContext->getBaggageItem($key);
    }

    private function mapErrorToStatusCode($value): string
    {
        if (in_array($value, ['true', true], true)) {
            return StatusCode::STATUS_ERROR;
        }
        if (in_array($value, ['false', false], true)) {
            return StatusCode::STATUS_OK;
        }

        return StatusCode::STATUS_UNSET;
    }
}
