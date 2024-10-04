<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Log\LogManager;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Logs\Severity;

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
     */
    public function recordLog(MessageLogged $log): void
    {
        $underlyingLogger = $this->logManager->getLogger();

        /** @phan-suppress-next-line PhanUndeclaredMethod */
        if (method_exists($underlyingLogger, 'isHandling') && !$underlyingLogger->isHandling($log->level)) {
            return;
        }

        $attributes = [
            'context' => json_encode(array_filter($log->context)),
        ];

        $record = (new LogRecord($log->message))
            ->setSeverityText($log->level)
            ->setSeverityNumber(Severity::fromPsr3($log->level))
            ->setAttributes($attributes);

        $this->logger->emit($record);
    }
}
