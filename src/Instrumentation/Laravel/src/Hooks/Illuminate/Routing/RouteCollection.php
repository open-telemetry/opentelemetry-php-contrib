<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Routing;

use Illuminate\Routing\RouteCollection as LaravelRouteCollection;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHookTrait;
use function OpenTelemetry\Instrumentation\hook;
use Throwable;

/**
 * Enhanced instrumentation for handling OPTIONS requests in Laravel applications.
 */
class RouteCollection implements LaravelHook
{
    use LaravelHookTrait;

    public function instrument(): void
    {
        $this->hookGetRouteForMethods();
    }

    /**
     * Hook into RouteCollection::getRouteForMethods to better name OPTIONS requests
     * to avoid creating MGIs (multiple grouped items).
     */
    protected function hookGetRouteForMethods(): bool
    {
        return hook(
            LaravelRouteCollection::class,
            'getRouteForMethods',
            post: function (LaravelRouteCollection $collection, array $params, $route, ?Throwable $exception) {
                // If the method couldn't find a route or there was an exception, don't do anything
                if (!$route || $exception) {
                    return;
                }

                // Grab the request from the parameters
                $request = $params[0] ?? null;
                if (!$request || !method_exists($request, 'method')) {
                    return;
                }

                // Only care about OPTIONS requests
                $httpMethod = $request->method();
                if ($httpMethod !== 'OPTIONS') {
                    return;
                }

                // Check if the route has a name - we only want to process unnamed routes
                if (!method_exists($route, 'getName')) {
                    return;
                }

                $routeName = $route->getName();
                if ($routeName) {
                    return;
                }

                // For OPTIONS requests without a name, give it a special name to avoid MGIs
                $route->name('_CORS_OPTIONS');

                // Update the current span name to match this CORS OPTIONS request
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }

                $span = Span::fromContext($scope->context());
                $span->updateName('OPTIONS _CORS_OPTIONS');
                $span->setAttribute('laravel.route.name', '_CORS_OPTIONS');
                $span->setAttribute('laravel.route.type', 'cors-options');
            }
        );
    }
}
