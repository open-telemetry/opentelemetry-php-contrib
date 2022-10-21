<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Slim;

use OpenTelemetry\API\Common\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\CallableFormatter;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Interfaces\InvocationStrategyInterface;
use Slim\Middleware\RoutingMiddleware;
use Slim\Routing\RouteContext;
use Throwable;

class SlimInstrumentation
{
    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.slim');
        // @var SpanInterface $root
        $root = null; //todo probably better to store this in context
        hook(
            App::class,
            'handle',
            static function (App $app, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, &$root) {
                $request = $params[0]; // @var ServerRequestInterface $request
                $root = $instrumentation->tracer()->spanBuilder(sprintf('HTTP %s', $request->getMethod()))
                    ->setAttribute('code.function', $function)
                    ->setAttribute('code.namespace', $class)
                    ->setAttribute('code.filepath', $filename)
                    ->setAttribute('code.lineno', $lineno)
                    ->setAttribute(TraceAttributes::HTTP_URL, (string) $request->getUri())
                    ->setAttribute(TraceAttributes::HTTP_METHOD, $request->getMethod())
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_CONTENT_LENGTH, $request->getHeaderLine('Content-Length'))
                    ->setAttribute(TraceAttributes::HTTP_SCHEME, $request->getUri()->getScheme())
                    ->startSpan();
                Context::storage()->attach($root->storeInContext(Context::getCurrent()));
            },
            static function (App $app, array $params, ?ResponseInterface $response, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                $scope?->detach();
                if (!$scope || $scope->context() === Context::getCurrent()) {
                    return;
                }
                $span = Span::fromContext($scope->context());
                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
            }
        );
        //update root span's name after routing
        hook(
            RoutingMiddleware::class,
            'performRouting',
            null,
            static function (RoutingMiddleware $middleware, array $params, ServerRequestInterface $new, ?Throwable $exception) use (&$root) {
                if ($exception) {
                    return;
                }
                // @var ServerRequestInterface $request
                $request = $params[0];
                // @var \Slim\Interfaces\RouteInterface $route
                $route = $new->getAttribute(RouteContext::ROUTE);
                $root->updateName($route->getName() ?? sprintf('%s %s', $request->getMethod(), (string) $route->getPattern()));
            }
        );
        //route callable/action
        hook(
            InvocationStrategyInterface::class,
            '__invoke',
            static function (InvocationStrategyInterface $strategy, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $callable = $params[0];
                $name = CallableFormatter::format($callable);
                $span = $instrumentation->tracer()->spanBuilder($name)
                    ->setAttribute('code.function', $function)
                    ->setAttribute('code.namespace', $class)
                    ->setAttribute('code.filepath', $filename)
                    ->setAttribute('code.lineno', $lineno)
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            static function (InvocationStrategyInterface $strategy, array $params, ?ResponseInterface $response, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                $scope?->detach();
                $span = Span::fromContext($scope->context());
                $exception && $span->recordException($exception);
                $span->end();
            }
        );
    }
}
