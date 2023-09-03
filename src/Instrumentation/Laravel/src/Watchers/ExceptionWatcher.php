<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\Events\MessageLogged;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

class ExceptionWatcher extends Watcher
{
    /** @psalm-suppress UndefinedInterfaceMethod */
    public function register(Application $app): void
    {
        $app['events']->listen(MessageLogged::class, [$this, 'recordException']);
    }
    /**
     * Record an exception.
     */
    public function recordException(MessageLogged $log): void
    {
        if (! isset($log->context['exception']) ||
        ! $log->context['exception'] instanceof Throwable) {
            return;
        }

        $exception = $log->context['exception'];

        $attributes = [
            TraceAttributes::CODE_NAMESPACE => get_class($exception),
            TraceAttributes::CODE_FILEPATH => $exception->getFile(),
            TraceAttributes::CODE_LINENO => $exception->getLine(),
        ];
        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }
        $span = Span::fromContext($scope->context());
        $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
    }
}
