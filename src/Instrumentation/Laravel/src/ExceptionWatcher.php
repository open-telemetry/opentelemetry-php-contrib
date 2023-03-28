<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel;

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
            'code.namespace' => get_class($exception),
            'code.filepath' => $exception->getFile(),
            'code.lineno' => $exception->getLine(),
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
