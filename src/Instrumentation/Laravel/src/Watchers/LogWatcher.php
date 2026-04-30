<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Log\LogManager;
use Illuminate\Support\Arr;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Instrumentation\ConfigurationResolver;
use OpenTelemetry\API\Logs\Severity;
use Stringable;
use Throwable;
use TypeError;

class LogWatcher extends Watcher
{
    public const OTEL_PHP_LARAVEL_LOG_ATTRIBUTES_FLATTEN = 'OTEL_PHP_LARAVEL_LOG_ATTRIBUTES_FLATTEN';

    private LogManager $logger;
    private bool $flattenAttributes;

    public function __construct(
        private CachedInstrumentation $instrumentation,
    ) {
        $resolver = new ConfigurationResolver();
        $this->flattenAttributes = $resolver->has(self::OTEL_PHP_LARAVEL_LOG_ATTRIBUTES_FLATTEN)
            && $resolver->getBoolean(self::OTEL_PHP_LARAVEL_LOG_ATTRIBUTES_FLATTEN);
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
        $underlyingLogger = $this->logger->getLogger();

        /**
         * This assumes that the underlying logger (expected to be monolog) would accept `$log->level` as a string.
         * With monolog < 3.x, this method would fail. Let's prevent this blowing up in Laravel<10.x.
         */
        try {
            /** @phan-suppress-next-line PhanUndeclaredMethod */
            if (method_exists($underlyingLogger, 'isHandling') && !$underlyingLogger->isHandling($log->level)) {
                return;
            }
        } catch (TypeError) {
            // Should this fail, we should continue to emit the LogRecord.
        }

        $logBuilder = $this->instrumentation
            ->logger()
            ->logRecordBuilder();

        $context = array_filter($log->context, static fn ($value) => $value !== null);
        $exception = $this->getExceptionFromContext($log->context);

        if ($exception !== null) {
            $logBuilder->setException($exception);

            unset($context['exception']);
        }

        $logBuilder->setBody($log->message)
            ->setSeverityText($log->level)
            ->setSeverityNumber(Severity::fromPsr3($log->level));

        if ($this->flattenAttributes) {
            foreach (Arr::dot($context) as $key => $value) {
                $logBuilder->setAttribute((string) $key, $this->normalizeValue($value));
            }
        } else {
            $logBuilder->setAttribute('context', $context);
        }

        $logBuilder->emit();
    }

    private function normalizeValue(mixed $value): string|bool|int|float|null
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }

        return json_encode($value) ?: null;
    }

    private function getExceptionFromContext(array $context): ?Throwable
    {
        if (
            !isset($context['exception']) ||
            !$context['exception'] instanceof Throwable
        ) {
            return null;
        }

        return $context['exception'];
    }
}
