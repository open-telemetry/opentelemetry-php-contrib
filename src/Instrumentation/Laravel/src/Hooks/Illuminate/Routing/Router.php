<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Routing;

use Illuminate\Routing\Router as LaravelRouter;
use Illuminate\Routing\RouteCollection;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHookTrait;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

/**
 * Enhanced instrumentation for Laravel's router component.
 */
class Router implements LaravelHook
{
    use LaravelHookTrait;

    public function instrument(): void
    {
        $this->hookPrepareResponse();
        $this->hookRouteCollection();
    }

    /**
     * Hook into Router::prepareResponse to update transaction naming when a response is prepared.
     */
    protected function hookPrepareResponse(): bool
    {
        return hook(
            LaravelRouter::class,
            'prepareResponse',
            post: function (LaravelRouter $router, array $params, $response, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }

                $span = Span::fromContext($scope->context());
                $request = ($params[0] ?? null);

                if (!$request || !method_exists($request, 'route')) {
                    return;
                }

                $span->setAttribute('http.method', $request->getMethod());

                $route = $request->route();
                if (!$route) {
                    return;
                }

                // Get the controller action from the route
                $action = null;
                if (method_exists($route, 'getAction')) {
                    $action = $route->getAction();

                    if (is_array($action) && isset($action['controller'])) {
                        $span->updateName($action['controller']);
                        $span->setAttribute(TraceAttributes::CODE_NAMESPACE, $action['controller']);
                    } elseif (is_string($action)) {
                        $span->updateName($action);
                        $span->setAttribute(TraceAttributes::CODE_NAMESPACE, $action);
                    }
                }

                // Try to get route name or path if action wasn't available
                if (method_exists($route, 'getName') && $route->getName() && strpos($route->getName(), 'generated::') !== 0) {
                    $span->updateName("{$request->method()} " . $route->getName());
                    $span->setAttribute('laravel.route.name', $route->getName());
                } elseif (method_exists($route, 'uri')) {
                    $path = $route->uri();
                    $span->updateName("{$request->method()} /" . ltrim($path, '/'));
                    $span->setAttribute(TraceAttributes::HTTP_ROUTE, $path);
                } elseif (method_exists($route, 'getPath')) {
                    $path = $route->getPath();
                    $span->updateName("{$request->method()} /" . ltrim($path, '/'));
                    $span->setAttribute(TraceAttributes::HTTP_ROUTE, $path);
                }

                // Mark 5xx responses as errors
                if ($response && method_exists($response, 'getStatusCode') && $response->getStatusCode() >= 500) {
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }
            }
        );
    }

    /**
     * Hook into RouteCollection::getRouteForMethods to handle CORS/OPTIONS requests.
     */
    protected function hookRouteCollection(): bool
    {
        return hook(
            RouteCollection::class,
            'getRouteForMethods',
            post: function (RouteCollection $routeCollection, array $params, $route, ?Throwable $exception) {
                // If no route was found or there was an exception, don't do anything
                if (!$route || $exception) {
                    return;
                }

                $request = $params[0] ?? null;
                if (!$request || !method_exists($request, 'method')) {
                    return;
                }

                $method = $request->method();
                if ($method !== 'OPTIONS') {
                    return;
                }

                // Check if this route has a name and if not, give it a special name for OPTIONS requests
                if (method_exists($route, 'getName') && !$route->getName()) {
                    $route->name('_CORS_OPTIONS');
                }
            }
        );
    }
} 