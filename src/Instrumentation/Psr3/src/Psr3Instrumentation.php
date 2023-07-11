<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Psr3;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs as API;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use Psr\Log\LoggerInterface;

class Psr3Instrumentation
{
    public const MODE_INJECT = 'inject';
    public const MODE_OTLP = 'otlp';
    private const MODES = [
        self::MODE_INJECT,
        self::MODE_OTLP,
    ];
    public const DEFAULT_MODE = self::MODE_INJECT;

    /** @psalm-suppress ArgumentTypeCoercion */
    public const NAME = 'psr3';
    private static string $mode;

    public static function register(): void
    {
        self::$mode = self::getMode();
        $pre = static function (LoggerInterface $object, array $params, string $class, string $function): array {
            switch (self::$mode) {
                case self::MODE_INJECT:
                    $context = Context::getCurrent();
                    $span = Span::fromContext($context)->getContext();

                    if (!$span->isValid()) {
                        return $params;
                    }

                    $ctxIdx = $function === 'log' ? 2 : 1;
                    $params[$ctxIdx] ??= [];
                    $params[$ctxIdx]['traceId'] = $span->getTraceId();
                    $params[$ctxIdx]['spanId'] = $span->getSpanId();

                    break;
                case self::MODE_OTLP:
                    static $logger;
                    $logger ??= Globals::loggerProvider()->getLogger(self::NAME);
                    $level = $params[0];
                    $body = $params[1];
                    $context = $params[2] ?? [];

                    $record = (new API\LogRecord($body))
                        ->setSeverityNumber(API\Map\Psr3::severityNumber($level))
                        ->setAttributes(Formatter::format($context));
                    $logger->emit($record);

                    break;
            }

            return $params;
        };

        hook(class: LoggerInterface::class, function: 'log', pre: $pre);
        if (self::shouldObserveSeverityMethods()) {
            foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $f) {
                hook(class: LoggerInterface::class, function: $f, pre: $pre);
            }
        }
    }

    private static function getMode(): string
    {
        $val = self::getEnvValue('OTEL_PHP_PSR3_MODE', self::DEFAULT_MODE);
        if (in_array($val, self::MODES)) {
            return $val;
        }
        //unknown mode, use default
        return self::DEFAULT_MODE;
    }

    private static function shouldObserveSeverityMethods(): bool
    {
        $val = self::getEnvValue('OTEL_PHP_PSR3_OBSERVE_ALL_METHODS', 'true');

        return $val !== 'false';
    }

    private static function getEnvValue(string $name, string $default)
    {
        $val = getenv($name);
        if ($val === false && array_key_exists($name, $_ENV)) {
            $val = $_ENV[$name];
        }

        return $val ?: $default;
    }
}
