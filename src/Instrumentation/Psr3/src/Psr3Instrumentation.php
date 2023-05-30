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

            if ($function === 'log') {
                $level = $params[0] ?? '';
                $message = $params[1] ?? '';
                $context = $params[2] ?? [];

                $context['traceId'] = $span->getTraceId();
                $context['spanId'] = $span->getSpanId();

                return [$level, $message, $context];
            } else {
                $message = $params[0] ?? '';
                $context = $params[1] ?? [];

                $context['traceId'] = $span->getTraceId();
                $context['spanId'] = $span->getSpanId();

                return [$message, $context];
            }
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
