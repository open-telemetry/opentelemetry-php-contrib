<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Log\LogManager;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Instrumentation\ConfigurationResolver;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\SDK\Common\Exception\StackTraceFormatter;
use Stringable;
use Throwable;
use TypeError;

class LogWatcher extends Watcher
{
    /**
     * When enabled, log context attributes are spread as individual OTLP attributes
     * instead of being JSON-encoded into a single 'context' attribute.
     * This improves searchability in observability backends like SigNoz.
     */
    public const OTEL_PHP_LARAVEL_LOG_ATTRIBUTES_FLATTEN = 'OTEL_PHP_LARAVEL_LOG_ATTRIBUTES_FLATTEN';

    private ?LogManager $logger = null;
    private bool $flattenAttributes;

    public function __construct(
        private CachedInstrumentation $instrumentation,
    ) {
        $this->flattenAttributes = $this->shouldFlattenAttributes();
    }

    /** @psalm-suppress UndefinedInterfaceMethod */
    public function register(Application $app): void
    {
        /** @phan-suppress-next-line PhanTypeArraySuspicious */
        $app['events']->listen(MessageLogged::class, [$this, 'recordLog']);

        /** @phan-suppress-next-line PhanTypeArraySuspicious */
        $this->logger = $app['log'];
    }

    /**
     * Record a log.
     * @phan-suppress PhanDeprecatedFunction
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function recordLog(MessageLogged $log): void
    {
        /**
         * This assumes that the underlying logger (expected to be monolog) would accept `$log->level` as a string.
         * With monolog < 3.x, this method would fail. Let's prevent this blowing up in Laravel<10.x.
         */
        if ($this->logger !== null) {
            try {
                $underlyingLogger = $this->logger->getLogger();
                /** @phan-suppress-next-line PhanUndeclaredMethod */
                if (method_exists($underlyingLogger, 'isHandling') && !$underlyingLogger->isHandling($log->level)) {
                    return;
                }
            } catch (TypeError) {
                // Should this fail, we should continue to emit the LogRecord.
            }
        }

        $contextToProcess = array_filter($log->context);

        $exception = $this->getExceptionFromContext($log->context);

        if ($exception !== null) {
            unset($contextToProcess['exception']);
        }

        $attributes = $this->flattenAttributes
            ? $this->buildFlattenedAttributes($contextToProcess)
            : ['context' => json_encode($contextToProcess)];

        if ($exception !== null) {
            $attributes['exception.type'] = $exception::class;
            $attributes['exception.message'] = $exception->getMessage();
            $attributes['exception.stacktrace'] = StackTraceFormatter::format($exception);
        }

        $logger = $this->instrumentation->logger();

        $record = (new LogRecord($log->message))
            ->setSeverityText($log->level)
            ->setSeverityNumber(Severity::fromPsr3($log->level))
            ->setAttributes($attributes);

        $logger->emit($record);
    }

    private function getExceptionFromContext(array $context): ?Throwable
    {
        if (
            ! isset($context['exception']) ||
            ! $context['exception'] instanceof Throwable
        ) {
            return null;
        }

        return $context['exception'];
    }

    private function shouldFlattenAttributes(): bool
    {
        $resolver = new ConfigurationResolver();

        return $resolver->has(self::OTEL_PHP_LARAVEL_LOG_ATTRIBUTES_FLATTEN)
            && $resolver->getBoolean(self::OTEL_PHP_LARAVEL_LOG_ATTRIBUTES_FLATTEN);
    }

    /**
     * Build flattened attributes from context array.
     * Nested arrays are flattened with dot notation for better searchability.
     *
     * @return array<string, mixed>
     */
    private function buildFlattenedAttributes(array $context): array
    {
        $attributes = [];

        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $this->flattenArray((string) $key, $value, $attributes);
            } else {
                $attributes[(string) $key] = $this->normalizeValue($value);
            }
        }

        return $attributes;
    }

    /**
     * Recursively flatten a nested array into dot-notation keys.
     */
    private function flattenArray(string $prefix, array $array, array &$result): void
    {
        foreach ($array as $key => $value) {
            $newKey = $prefix . '.' . $key;

            if (is_array($value)) {
                $this->flattenArray($newKey, $value, $result);
            } else {
                $result[$newKey] = $this->normalizeValue($value);
            }
        }
    }

    /**
     * Normalize a value for OTLP attributes.
     * OTLP attributes support: string, bool, int, float, and arrays of these.
     */
    private function normalizeValue(mixed $value): string|bool|int|float|null
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        // For objects that can't be stringified, JSON encode them
        return json_encode($value);
    }
}
