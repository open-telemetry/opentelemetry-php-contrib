<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Log\LogManager;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\Context;

class LogWatcher extends Watcher
{
    /** @var LogManager */
    private LogManager $logger;

    /** @psalm-suppress UndefinedInterfaceMethod */
    public function register(Application $app): void
    {
        /** @phan-suppress-next-line PhanTypeArraySuspicious */
        $app['events']->listen(MessageLogged::class, [$this, 'recordLog']);

        $this->logger = $app['log'];
    }

    /**
     * Record a log.
     */
    public function recordLog(MessageLogged $log): void
    {
        if (!$this->logger->isHandling($log->level)) {
            return;
        }

        $attributes = [
            'level' => $log->level,
        ];

        $attributes['context'] = json_encode(array_filter($log->context));

        $message = $log->message;

        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }
        $span = Span::fromContext($scope->context());
        $span->addEvent($message, $attributes);
    }
}
