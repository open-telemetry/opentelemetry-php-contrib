<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Slim;

use OpenTelemetry\API\Common\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Common\Instrumentation\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
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
         * Create a span representing the http transaction for Slim\App::handle
         * @psalm-suppress ArgumentTypeCoercion
         */
        hook(
            App::class,
            'handle',
            pre: static function (RequestHandlerInterface $handler, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $request = ($params[0] instanceof ServerRequestInterface) ? $params[0] : null;
                $parent = Context::getCurrent();
                $builder = $instrumentation->tracer()->spanBuilder(sprintf('HTTP %s', $request?->getMethod() ?? 'unknown'))
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute('code.function', $function)
                    ->setAttribute('code.namespace', $class)
                    ->setAttribute('code.filepath', $filename)
                    ->setAttribute('code.lineno', $lineno);
                if ($request) {
                    //extract trace propagation headers from request
                    $parent = Globals::propagator()->extract($request->getHeaders());

                    $builder = $builder
                        ->setAttribute(TraceAttributes::HTTP_URL, $request->getUri()->__toString())
                        ->setAttribute(TraceAttributes::HTTP_METHOD, $request->getMethod())
                        ->setAttribute(TraceAttributes::HTTP_REQUEST_CONTENT_LENGTH, $request->getHeaderLine('Content-Length'))
                        ->setAttribute(TraceAttributes::HTTP_SCHEME, $request->getUri()->getScheme());
                }
                $span = $builder->setParent($parent)->startSpan();

                Context::storage()->attach($span->storeInContext($parent));
                if ($request) {
                    return [$request->withAttribute(SpanInterface::class, $span)];
                }

                return [];
            },
            post: static function (RequestHandlerInterface $handler, array $params, ?ResponseInterface $response, ?Throwable $exception) {
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
                if ($response?->getStatusCode() >= 500) {
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }

                $span->end();
            }
        );

        /**
         * Update root span's name after Slim routing, using either route name or method+pattern.
         * This relies upon the existence of a request attribute with key SpanInterface::class
         * and type SpanInterface which represents the root span, having been previously set (eg
         * by Psr15 auto-instrumentation)
         *
         * @psalm-suppress ArgumentTypeCoercion
         */
        hook(
            RoutingMiddleware::class,
            'performRouting',
            pre: null,
            post: static function (RoutingMiddleware $middleware, array $params, ServerRequestInterface $request, ?Throwable $exception) {
                if ($exception) {
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
