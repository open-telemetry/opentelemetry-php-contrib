<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Propagation\TraceResponse;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;

/**
 * Provides a ResponsePropagator for the Trace Context HTTP Response Headers Format
 *
 * @see https://w3c.github.io/trace-context/#trace-context-http-response-headers-format
 */
final class TraceResponsePropagator implements ResponsePropagator
{
    const IS_SAMPLED = '01';
    const NOT_SAMPLED = '00';
    const SUPPORTED_VERSION = '00';
    const TRACERESPONSE = 'traceresponse';

    public function fields(): array
    {
        return [
            self::TRACERESPONSE,
        ];
    }

    public function inject(&$carrier, ?PropagationSetterInterface $setter = null, ?ContextInterface $context = null): void
    {
        $setter = $setter ?? ArrayAccessGetterSetter::getInstance();
        $context = $context ?? Context::getCurrent();
        $spanContext = Span::fromContext($context)->getContext();

        if (!$spanContext->isValid()) {
            return;
        }

        $traceId = $spanContext->getTraceId();
        $spanId = $spanContext->getSpanId();

        $samplingFlag = $spanContext->isSampled() ? self::IS_SAMPLED : self::NOT_SAMPLED;

        $header = self::SUPPORTED_VERSION . '-' . $traceId . '-' . $spanId . '-' . $samplingFlag;
        $setter->set($carrier, self::TRACERESPONSE, $header);
    }
}
