<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Routing;

use Illuminate\Routing\Route as LaravelRoute;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHookTrait;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\PostHookTrait;
use function OpenTelemetry\Instrumentation\hook;
use Throwable;

/**
 * Enhanced instrumentation for Laravel's route execution.
 */
class Route implements LaravelHook
{
    use LaravelHookTrait;
    use PostHookTrait;

    public function instrument(): void
    {
        $this->hookRun();
    }

    /**
     * Hook into Route::run to track controller execution.
     */
    protected function hookRun(): bool
    {
        return hook(
            LaravelRoute::class,
            'run',
            pre: function (LaravelRoute $route, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                // Get the route action
                $action = $route->getAction();
                
                // Check if this is a controller route
                if (is_array($action)) {
                    $controllerClass = null;
                    $method = null;
                    
                    // Handle array format ['Controller', 'method']
                    if (isset($action[0]) && isset($action[1])) {
                        $controllerClass = $action[0];
                        $method = $action[1];
                    }
                    // Handle array format ['controller' => 'Controller@method']
                    elseif (isset($action['controller'])) {
                        $controller = $action['controller'];
                        if (is_string($controller) && str_contains($controller, '@')) {
                            [$controllerClass, $method] = explode('@', $controller);
                        }
                    }
                    
                    if ($controllerClass && $method) {
                        // Hook into the controller method execution
                        hook(
                            $controllerClass,
                            $method,
                            pre: function ($controller, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                                $spanBuilder = $this->instrumentation->tracer()
                                    ->spanBuilder("Controller::{$function}");
                                $span = $spanBuilder->setSpanKind(SpanKind::KIND_INTERNAL)->startSpan();
                                
                                // Add code attributes
                                $span->setAttribute('code.function.name', $function);
                                $span->setAttribute('code.namespace', $class);
                                
                                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
                                
                                return $params;
                            },
                            post: function ($controller, array $params, mixed $response, ?Throwable $exception) {
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
                } elseif ($action instanceof \Closure) {
                    // Add closure information to the existing span
                    $scope = Context::storage()->scope();
                    if (!$scope) {
                        return $params;
                    }
                    
                    $span = Span::fromContext($scope->context());
                    $span->setAttribute('code.function.name', '{closure}');
                    $span->setAttribute('code.namespace', '');
                }

                return $params;
            }
        );
    }
}
