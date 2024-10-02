<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Psr3;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Instrumentation\ConfigurationResolver;
use OpenTelemetry\API\Logs as API;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

class Psr3Instrumentation
{
    const OTEL_PHP_PSR3_MODE = 'OTEL_PHP_PSR3_MODE';
    public const MODE_INJECT = 'inject';
    public const MODE_EXPORT = 'export';
    private const MODES = [
        self::MODE_INJECT,
        self::MODE_EXPORT,
    ];
    public const DEFAULT_MODE = self::MODE_INJECT;
    private static array $cache = [];

    /** @psalm-suppress ArgumentTypeCoercion */
    public const NAME = 'psr3';
    private static string $mode;

    public static function register(): void
    {
        self::$mode = self::getMode();
        $pre = static function (LoggerInterface $object, array $params, string $class, string $function): array {
            $id = spl_object_id($object);
            if (!array_key_exists($id, self::$cache)) {
                $traits = self::class_uses_deep($object);
                self::$cache[$id] = in_array(LoggerTrait::class, $traits);
            }
            if (self::$cache[$id] === true && $function !== 'log') {
                //LoggerTrait proxies all log-level-specific methods to `log`, which leads to double-processing
                //Not all psr-3 loggers use AbstractLogger, so we check for the trait directly
                return $params;
            }
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
                case self::MODE_EXPORT:
                    static $instrumentation;
                    $instrumentation ??= new CachedInstrumentation(
                        'io.opentelemetry.contrib.php.psr3',
                        null,
                        'https://opentelemetry.io/schemas/1.24.0'
                    );
                    if ($function === 'log') {
                        $level = $params[0];
                        $body = $params[1] ?? '';
                        $context = $params[2] ?? [];
                    } else {
                        $level = $function;
                        $body = $params[0] ?? '';
                        $context = $params[1] ?? [];
                    }

                    $record = (new API\LogRecord($body))
                        ->setSeverityNumber(API\Map\Psr3::severityNumber($level));
                    foreach (Formatter::format($context) as $key => $value) {
                        $record->setAttribute((string) $key, $value);
                    }
                    $instrumentation->logger()->emit($record);

                    break;
            }

            return $params;
        };

        foreach (['log', 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $f) {
            hook(class: LoggerInterface::class, function: $f, pre: $pre);
        }
    }

    private static function getMode(): string
    {
        $resolver = new ConfigurationResolver();
        if ($resolver->has(self::OTEL_PHP_PSR3_MODE)) {
            $val = $resolver->getString(self::OTEL_PHP_PSR3_MODE);
            if ($val && in_array($val, self::MODES)) {
                return $val;
            }
        }

        return self::DEFAULT_MODE;
    }

    /**
     * @see https://www.php.net/manual/en/function.class-uses.php#112671
     */
    private static function class_uses_deep(object $class): array
    {
        $traits = [];

        // Get traits of all parent classes
        do {
            $traits = array_merge(class_uses($class, false), $traits);
        } while ($class = get_parent_class($class));

        // Get traits of all parent traits
        $traitsToSearch = $traits;
        while (!empty($traitsToSearch)) {
            $newTraits = class_uses(array_pop($traitsToSearch), false);
            $traits = array_merge($newTraits, $traits);
            $traitsToSearch = array_merge($newTraits, $traitsToSearch);
        };

        foreach ($traits as $trait => $same) {
            $traits = array_merge(class_uses($trait, false), $traits);
        }

        return array_unique($traits);
    }
}
