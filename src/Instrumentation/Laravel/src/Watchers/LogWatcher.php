<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Log\LogManager;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\Severity;
use Throwable;
use TypeError;

class LogWatcher extends Watcher
{
    private LogManager $logManager;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @psalm-suppress UndefinedInterfaceMethod */
    public function register(Application $app): void
    {
        /** @phan-suppress-next-line PhanTypeArraySuspicious */
        $app['events']->listen(MessageLogged::class, [$this, 'recordLog']);

        /** @phan-suppress-next-line PhanTypeArraySuspicious */
        $this->logManager = $app['log'];
    }

    /**
     * Record a log.
     * @phan-suppress PhanDeprecatedFunction
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function recordLog(MessageLogged $log): void
    {
        $underlyingLogger = $this->logManager->getLogger();

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

        $logBuilder = $this
            ->logger
            ->logRecordBuilder();

        $context = array_filter($log->context, static fn ($value) => $value !== null);
        $exception = $this->getExceptionFromContext($log->context);

        if ($exception !== null) {
            $logBuilder->setException($exception);

            unset($context['exception']);
        }

        $logBuilder->setBody($log->message)
            ->setSeverityText($log->level)
            ->setSeverityNumber(Severity::fromPsr3($log->level))
            ->setAttribute('context', $context)
            ->emit();
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
