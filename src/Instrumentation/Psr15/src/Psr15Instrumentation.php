<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Psr15;

use OpenTelemetry\API\Common\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Throwable;

class Psr15Instrumentation
{
    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.psr15');
        hook(
            MiddlewareInterface::class,
            'process',
            static function (MiddlewareInterface $middleware, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $span = $instrumentation->tracer()->spanBuilder(sprintf('%s::%s', $class, $function))
                    ->setAttribute('code.function', $function)
                    ->setAttribute('code.namespace', $class)
                    ->setAttribute('code.filepath', $filename)
                    ->setAttribute('code.lineno', $lineno)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            static function (MiddlewareInterface $middleware, array $params, ?ResponseInterface $response, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                $scope?->detach();
                if (!$scope || $scope->context() === Context::getCurrent()) {
                    return;
                }
                $span = Span::fromContext($scope->context());
                $exception && $span->recordException($exception);
                $span->setStatus($exception ? StatusCode::STATUS_ERROR : StatusCode::STATUS_OK);
                $span->end();
            }
        );
    }
}
