<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Slim;

use OpenTelemetry\API\Common\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Psr15\Psr15Instrumentation;
use function OpenTelemetry\Instrumentation\hook;
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
         * The root span should have been created by psr-15 auto-instrumentation, and stored as
         * a request attribute.
         *
         * @phan-suppress PhanUndeclaredClassConstant (remove after publishing psr-15 package and adding to composer dependencies)
         * @psalm-suppress UndefinedClass
         * @psalm-suppress ArgumentTypeCoercion
         */
        hook(
            RoutingMiddleware::class,
            'performRouting',
            null,
            static function (RoutingMiddleware $middleware, array $params, ServerRequestInterface $new, ?Throwable $exception) {
                if ($exception) {
                    return;
                }
                $request = $params[0];
                if (!$request instanceof ServerRequestInterface) {
                    return;
                }
                $span = $request->getAttribute(Psr15Instrumentation::ROOT_SPAN); //@phpstan-ignore-line
                if (!$span instanceof SpanInterface) {
                    return;
                }
                $route = $request->getAttribute(RouteContext::ROUTE);
                if (!$route instanceof RouteInterface) {
                    return;
                }
                $span->updateName($route->getName() ?? sprintf('%s %s', $request->getMethod(), $route->getPattern())); //@phpstan-ignore-line
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
            static function (InvocationStrategyInterface $strategy, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
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
            static function (InvocationStrategyInterface $strategy, array $params, ?ResponseInterface $response, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                $scope?->detach();
                if (!$scope) {
                    return;
                }
                $span = Span::fromContext($scope->context());
                $exception && $span->recordException($exception);
                $span->end();
            }
        );
    }
}
