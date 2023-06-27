<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Psr3;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\Context;
use Psr\Log\LoggerInterface;

class Psr3Instrumentation
{
    /** @psalm-suppress ArgumentTypeCoercion */
    public const NAME = 'psr3';

    public static function register(): void
    {
        $pre = static function (LoggerInterface $object, ?array $params, ?string $class, string $function, ?string $filename, ?int $lineno): ?array {
            $context = Context::getCurrent();
            $span = Span::fromContext($context)->getContext();

            if (!$span->isValid()) {
                return $params;
            }

            $ctxIdx = $function === 'log' ? 2 : 1;

            $params[$ctxIdx] ??= [];
            $params[$ctxIdx]['traceId'] = $span->getTraceId();
            $params[$ctxIdx]['spanId'] = $span->getSpanId();

            return $params;
        };

        \OpenTelemetry\Instrumentation\hook(class: LoggerInterface::class, function: 'emergency', pre: $pre);
        \OpenTelemetry\Instrumentation\hook(class: LoggerInterface::class, function: 'alert', pre: $pre);
        \OpenTelemetry\Instrumentation\hook(class: LoggerInterface::class, function: 'critical', pre: $pre);
        \OpenTelemetry\Instrumentation\hook(class: LoggerInterface::class, function: 'error', pre: $pre);
        \OpenTelemetry\Instrumentation\hook(class: LoggerInterface::class, function: 'warning', pre: $pre);
        \OpenTelemetry\Instrumentation\hook(class: LoggerInterface::class, function: 'notice', pre: $pre);
        \OpenTelemetry\Instrumentation\hook(class: LoggerInterface::class, function: 'info', pre: $pre);
        \OpenTelemetry\Instrumentation\hook(class: LoggerInterface::class, function: 'debug', pre: $pre);
        \OpenTelemetry\Instrumentation\hook(class: LoggerInterface::class, function: 'log', pre: $pre);
    }
}
