<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Contracts\Debug;

use Exception;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHookTrait;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

/**
 * Enhanced instrumentation for Laravel's exception handler.
 */
class ExceptionHandler implements LaravelHook
{
    use LaravelHookTrait;

    public function instrument(): void
    {
        $this->hookRender();
        $this->hookReport();
    }

    /**
     * Hook into the render method to name the transaction when exceptions occur.
     */
    protected function hookRender(): bool
    {
        return hook(
            ExceptionHandlerContract::class,
            'render',
            pre: function (ExceptionHandlerContract $handler, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                $exception = $params[0] ?? null;
                
                // Name the transaction after the exception handler and method
                $spanName = $class . '@' . $function;
                
                // Try to get the current span
                $scope = Context::storage()->scope();
                if (!$scope) {
                    // If no span exists, create a new one for the exception handler
                    $span = $this->instrumentation
                        ->tracer()
                        ->spanBuilder($spanName)
                        ->setSpanKind(SpanKind::KIND_INTERNAL)
                        ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
                        ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                        ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                        ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                        ->startSpan();
                    
                    Context::storage()->attach($span->storeInContext(Context::getCurrent()));
                } else {
                    // If a span exists, update its name
                    $span = Span::fromContext($scope->context());
                    $span->updateName($spanName);
                }
                
                // Record exception information
                if ($exception instanceof Throwable) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                    $span->setAttribute('exception.class', get_class($exception));
                    $span->setAttribute('exception.message', $exception->getMessage());
                    
                    // Add file and line number where the exception occurred
                    $span->setAttribute('exception.file', $exception->getFile());
                    $span->setAttribute('exception.line', $exception->getLine());
                }
            }
        );
    }

    /**
     * Hook into the report method to record traced errors for unhandled exceptions.
     */
    protected function hookReport(): bool
    {
        return hook(
            ExceptionHandlerContract::class,
            'report',
            pre: function (ExceptionHandlerContract $handler, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                $exception = $params[0] ?? null;
                if (!$exception instanceof Throwable) {
                    return;
                }

                // Check if this exception should be reported
                // Laravel's default handler has a shouldReport method that returns false
                // if the exception should be ignored
                if (method_exists($handler, 'shouldReport') && !$handler->shouldReport($exception)) {
                    return;
                }

                // Get the current span (or create a new one)
                $scope = Context::storage()->scope();
                if (!$scope) {
                    $span = $this->instrumentation
                        ->tracer()
                        ->spanBuilder($class . '@' . $function)
                        ->setSpanKind(SpanKind::KIND_INTERNAL)
                        ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
                        ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                        ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                        ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                        ->startSpan();
                    
                    Context::storage()->attach($span->storeInContext(Context::getCurrent()));
                } else {
                    $span = Span::fromContext($scope->context());
                }

                // Record the exception details
                $span->recordException($exception);
                $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                $span->setAttribute('exception.class', get_class($exception));
                $span->setAttribute('exception.message', $exception->getMessage());
                $span->setAttribute('exception.file', $exception->getFile());
                $span->setAttribute('exception.line', $exception->getLine());
            }
        );
    }
} 