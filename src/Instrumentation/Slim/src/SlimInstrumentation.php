<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Slim;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Attributes\UserAgentAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Interfaces\InvocationStrategyInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Middleware\RoutingMiddleware;
use Slim\Routing\RouteContext;
use Throwable;

/** @psalm-suppress UnusedClass */
class SlimInstrumentation
{
    public const NAME = 'slim';
    private static bool $supportsResponsePropagation = false;

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.slim',
            null,
            'https://opentelemetry.io/schemas/1.32.0',
        );

        /**
         * requires extension >= 1.0.2beta2
         * @see https://github.com/open-telemetry/opentelemetry-php-instrumentation/pull/136
         */
        $otelVersion = phpversion('opentelemetry');
        self::$supportsResponsePropagation = $otelVersion !== false && version_compare($otelVersion, '1.0.2beta2') >= 0;

        /** @psalm-suppress UnusedFunctionCall */
        hook(
            App::class,
            'handle',
            pre: static function (App $app, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $request = ($params[0] instanceof ServerRequestInterface) ? $params[0] : null;
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation->tracer()
                    ->spanBuilder(sprintf('%s', $request?->getMethod() ?? 'unknown'))
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);
                $parent = Context::getCurrent();
                if ($request) {
                    $parent = Globals::propagator()->extract($request->getHeaders());
                    $span = $builder
                        ->setParent($parent)
                        ->setAttribute(UrlAttributes::URL_FULL, $request->getUri()->__toString())
                        ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                        ->setAttribute(HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE, $request->getHeaderLine('Content-Length'))
                        ->setAttribute(UserAgentAttributes::USER_AGENT_ORIGINAL, $request->getHeaderLine('User-Agent'))
                        ->setAttribute(ServerAttributes::SERVER_ADDRESS, $request->getUri()->getHost())
                        ->setAttribute(ServerAttributes::SERVER_PORT, $request->getUri()->getPort())
                        ->setAttribute(UrlAttributes::URL_SCHEME, $request->getUri()->getScheme())
                        ->setAttribute(UrlAttributes::URL_PATH, $request->getUri()->getPath())
                        ->startSpan();
                    $request = $request->withAttribute(SpanInterface::class, $span);
                } else {
                    $span = $builder->startSpan();
                }
                Context::storage()->attach($span->storeInContext($parent));

                return [$request];
            },
            post: static function (App $app, array $params, ?ResponseInterface $response, ?Throwable $exception): ?ResponseInterface {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return $response;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());
                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }
                if ($response) {
                    if ($response->getStatusCode() >= 400) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }
                    $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
                    $span->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $response->getProtocolVersion());
                    $span->setAttribute(HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE, $response->getHeaderLine('Content-Length'));

                    if (self::$supportsResponsePropagation) {
                        $prop = Globals::responsePropagator();
                        $prop->inject($response, PsrResponsePropagationSetter::instance(), $scope->context());
                    }
                }
                $span->end();

                return $response;
            }
        );

        /**
         * Update root span's name after Slim routing, using either route name or method+pattern.
         * This relies upon the existence of a request attribute with key SpanInterface::class
         * and type SpanInterface which represents the root span, having been previously set
         * If routing fails (eg 404/not found), then the root span name will not be updated.
         *
         * @todo this can use LocalRootSpan (available since API 1.1.0)
         *
         * @psalm-suppress ArgumentTypeCoercion
         * @psalm-suppress UnusedFunctionCall
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
                $span->setAttribute(HttpAttributes::HTTP_ROUTE, $route->getName() ?? $route->getPattern());
                $span->updateName(sprintf('%s %s', $request->getMethod(), $route->getName() ?? $route->getPattern()));
            }
        );

        /**
         * Create a span for Slim route's action/controller/callable
         *
         * @psalm-suppress ArgumentTypeCoercion
         * @psalm-suppress UnusedFunctionCall
         */
        hook(
            InvocationStrategyInterface::class,
            '__invoke',
            pre: static function (InvocationStrategyInterface $strategy, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $callable = $params[0];
                $name = CallableFormatter::format($callable);
                $builder = $instrumentation->tracer()->spanBuilder($name)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);
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
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }
                $span->end();
            }
        );
    }
}
