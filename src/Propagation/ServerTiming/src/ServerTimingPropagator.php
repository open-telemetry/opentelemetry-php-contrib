<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Propagation\ServerTiming;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;

/**
 * Provides a ResponsePropagator for Server-Timings headers
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Server-Timing
 */
final class ServerTimingPropagator implements ResponsePropagator
{
    const IS_SAMPLED = '1';
    const NOT_SAMPLED = '0';
    const SUPPORTED_VERSION = '00';
    const SERVER_TIMING = 'server-timing';
    const TRACEPARENT = 'traceparent';

    public function fields(): array
    {
        return [
            self::SERVER_TIMING,
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

        $header = sprintf('%s;desc=%s-%s-%s-%s', self::TRACEPARENT, self::SUPPORTED_VERSION, $traceId, $spanId, $samplingFlag);
        $setter->set($carrier, self::SERVER_TIMING, $header);
    }
}
