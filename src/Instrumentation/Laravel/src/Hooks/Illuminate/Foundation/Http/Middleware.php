<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Foundation\Http;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHookTrait;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use ReflectionClass;
use Throwable;

/**
 * Enhanced instrumentation for Laravel's middleware components.
 */
class Middleware implements LaravelHook
{
    use LaravelHookTrait;

    public function instrument(): void
    {
        $this->setupMiddlewareHooks();
    }

    /**
     * Find and hook all global middleware classes.
     */
    protected function setupMiddlewareHooks(): void
    {
        hook(
            Application::class,
            'boot',
            post: function (Application $app, array $params, $result, ?Throwable $exception) {
                // Abort if there was an exception
                if ($exception) {
                    return;
                }

                try {
                    // Get the HTTP kernel and its middleware
                    if (!$app->has(HttpKernel::class)) {
                        return;
                    }

                    /** @var HttpKernel $kernel */
                    $kernel = $app->make(HttpKernel::class);

                    // Get middleware property using reflection (different between Laravel versions)
                    $reflectionClass = new ReflectionClass($kernel);
                    $middlewareProperty = null;

                    if ($reflectionClass->hasProperty('middleware')) {
                        $middlewareProperty = $reflectionClass->getProperty('middleware');
                        $middlewareProperty->setAccessible(true);
                        $middleware = $middlewareProperty->getValue($kernel);
                    } elseif (method_exists($kernel, 'getMiddleware')) {
                        $middleware = $kernel->getMiddleware();
                    } else {
                        return;
                    }

                    // Hook each middleware
                    if (is_array($middleware)) {
                        foreach ($middleware as $middlewareClass) {
                            if (is_string($middlewareClass) && class_exists($middlewareClass)) {
                                $this->hookMiddlewareClass($middlewareClass);
                            }
                        }
                    }

                    // Also hook middleware groups
                    if ($reflectionClass->hasProperty('middlewareGroups')) {
                        $middlewareGroupsProperty = $reflectionClass->getProperty('middlewareGroups');
                        $middlewareGroupsProperty->setAccessible(true);
                        $middlewareGroups = $middlewareGroupsProperty->getValue($kernel);

                        if (is_array($middlewareGroups)) {
                            foreach ($middlewareGroups as $groupName => $middlewareList) {
                                if (is_array($middlewareList)) {
                                    foreach ($middlewareList as $middlewareItem) {
                                        if (is_string($middlewareItem) && class_exists($middlewareItem)) {
                                            $this->hookMiddlewareClass($middlewareItem, $groupName);
                                        }
                                    }
                                }
                            }
                        }
                    }
                } catch (Throwable $e) {
                    // Swallow exceptions to prevent breaking the application
                }
            }
        );
    }

    /**
     * Hook an individual middleware class.
     */
    protected function hookMiddlewareClass(string $middlewareClass, ?string $group = null): void
    {
        // Check if the class exists and has a handle method
        if (!class_exists($middlewareClass) || !method_exists($middlewareClass, 'handle')) {
            return;
        }

        // Hook the handle method
        hook(
            $middlewareClass,
            'handle',
            pre: function (object $middleware, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($group) {
                $spanName = $class . '::' . $function;
                $span = $this->instrumentation
                    ->tracer()
                    ->spanBuilder($spanName)
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute('laravel.middleware.class', $class);

                if ($group) {
                    $span->setAttribute('laravel.middleware.group', $group);
                }

                $newSpan = $span->startSpan();
                $context = $newSpan->storeInContext(Context::getCurrent());
                Context::storage()->attach($context);
            },
            post: function (object $middleware, array $params, $response, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }

                $span = Span::fromContext($scope->context());

                // Record any exceptions
                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                // If this middleware short-circuits the request by returning a response,
                // capture the response information
                if ($response && method_exists($response, 'getStatusCode')) {
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());

                    // Mark 5xx responses as errors
                    if ($response->getStatusCode() >= 500) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }
                }

                $scope->detach();
                // End the span
                $span->end();
            }
        );
    }
} 