<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Slim;

use OpenTelemetry\API\Common\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\InvocationStrategyInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Middleware\RoutingMiddleware;
use Slim\Routing\RouteContext;
use Throwable;

class SlimInstrumentation
{
    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.slim');

        /**
         * Update root span's name after Slim routing, using either route name or method+pattern.
         * This relies upon the existence of a request attribute with key SpanInterface::class
         * and type SpanInterface which represents the root span, having been previously set (eg
         * by PSR-15 auto-instrumentation)
         * If routing fails (eg 404/not found), then the root span name will not be updated.
         *
         * @psalm-suppress ArgumentTypeCoercion
         */
        hook(
            RoutingMiddleware::class,
            'performRouting',
            pre: null,
            post: static function (RoutingMiddleware $middleware, array $params, ?ServerRequestInterface $request, ?Throwable $exception) {
                if ($exception || !$request) {
                    return;
                }
                $span = $request->getAttribute(SpanInterface::class);
                if (!$span instanceof SpanInterface) {
                    return;
                }
                $route = $request->getAttribute(RouteContext::ROUTE);
                if (!$route instanceof RouteInterface) {
                    return;
                }
                $span->updateName(sprintf('%s %s', $request->getMethod(), $route->getName() ?? $route->getPattern()));
            }
        );

        /**
         * Create a span for Slim route's action/controller/callable
         *
         * @psalm-suppress ArgumentTypeCoercion
         */
        hook(
            InvocationStrategyInterface::class,
            '__invoke',
            pre: static function (InvocationStrategyInterface $strategy, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $callable = $params[0];
                $name = CallableFormatter::format($callable);
                $builder = $instrumentation->tracer()->spanBuilder($name) //@phpstan-ignore-line
                    ->setAttribute('code.function', $function)
                    ->setAttribute('code.namespace', $class)
                    ->setAttribute('code.filepath', $filename)
                    ->setAttribute('code.lineno', $lineno);
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (InvocationStrategyInterface $strategy, array $params, ?ResponseInterface $response, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());
                if ($exception) {
                    $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }
                $span->end();
            }
        );
    }
}
