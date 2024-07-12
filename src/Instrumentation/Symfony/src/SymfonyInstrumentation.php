<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Symfony;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use Symfony\Component\HttpKernel\KernelEvents;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/** @psalm-suppress UnusedClass */
final class SymfonyInstrumentation
{
    public const NAME = 'symfony';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.symfony',
            null,
            'https://opentelemetry.io/schemas/1.30.0',
        );

        /** @psalm-suppress UnusedFunctionCall */
        hook(
            HttpKernel::class,
            'handle',
            pre: static function (
                HttpKernel $kernel,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): array {
                $request = ($params[0] instanceof Request) ? $params[0] : null;
                $type = $params[1] ?? HttpKernelInterface::MAIN_REQUEST;
                $method = $request?->getMethod() ?? 'unknown';
                $name = ($type === HttpKernelInterface::SUB_REQUEST)
                    ? sprintf('%s %s', $method, $request?->attributes?->get('_controller') ?? 'sub-request')
                    : $method;
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder($name)
                    ->setSpanKind(($type === HttpKernelInterface::SUB_REQUEST) ? SpanKind::KIND_INTERNAL : SpanKind::KIND_SERVER)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno);

                $parent = Context::getCurrent();
                if ($request) {
                    $parent = Globals::propagator()->extract($request, RequestPropagationGetter::instance());
                    $span = $builder
                        ->setParent($parent)
                        ->setAttribute(TraceAttributes::URL_FULL, $request->getUri())
                        ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                        ->setAttribute(TraceAttributes::HTTP_REQUEST_BODY_SIZE, $request->headers->get('Content-Length'))
                        ->setAttribute(TraceAttributes::URL_SCHEME, $request->getScheme())
                        ->setAttribute(TraceAttributes::URL_PATH, $request->getPathInfo())
                        ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $request->headers->get('User-Agent'))
                        ->setAttribute(TraceAttributes::SERVER_ADDRESS, $request->getHost())
                        ->setAttribute(TraceAttributes::SERVER_PORT, $request->getPort())
                        ->startSpan();
                    $request->attributes->set(SpanInterface::class, $span);
                } else {
                    $span = $builder->startSpan();
                }
                Context::storage()->attach($span->storeInContext($parent));

                return [$request];
            },
            post: static function (
                HttpKernel $kernel,
                array $params,
                ?Response $response,
                ?\Throwable $exception
            ): void {
                $scope = Context::storage()->scope();
                if (null === $scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());

                $request = ($params[0] instanceof Request) ? $params[0] : null;
                if (null !== $request) {
                    $routeName = $request->attributes->get('_route', '');

                    if ('' !== $routeName) {
                        /** @psalm-suppress ArgumentTypeCoercion */
                        $span
                            ->updateName(sprintf('%s %s', $request->getMethod(), $routeName))
                            ->setAttribute(TraceAttributes::HTTP_ROUTE, $routeName);
                    }
                }

                if (null !== $exception) {
                    $span->recordException($exception);
                    if (null !== $response && $response->getStatusCode() >= Response::HTTP_INTERNAL_SERVER_ERROR) {
                        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                    }
                }

                if (null === $response) {
                    $span->end();

                    return;
                }

                if ($response->getStatusCode() >= Response::HTTP_INTERNAL_SERVER_ERROR) {
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }
                $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
                $span->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $response->getProtocolVersion());
                $contentLength = $response->headers->get('Content-Length');
                /** @psalm-suppress PossiblyFalseArgument */
                if (null === $contentLength && is_string($response->getContent())) {
                    $contentLength = \strlen($response->getContent());
                }

                $span->setAttribute(TraceAttributes::HTTP_RESPONSE_BODY_SIZE, $contentLength);

                // Propagate server-timing header to response, if ServerTimingPropagator is present
                if (class_exists('OpenTelemetry\Contrib\Propagation\ServerTiming\ServerTimingPropagator')) {
                    $prop = new \OpenTelemetry\Contrib\Propagation\ServerTiming\ServerTimingPropagator();
                    $prop->inject($response, ResponsePropagationSetter::instance(), $scope->context());
                }

                // Propagate traceresponse header to response, if TraceResponsePropagator is present
                if (class_exists('OpenTelemetry\Contrib\Propagation\TraceResponse\TraceResponsePropagator')) {
                    $prop = new \OpenTelemetry\Contrib\Propagation\TraceResponse\TraceResponsePropagator();
                    $prop->inject($response, ResponsePropagationSetter::instance(), $scope->context());
                }

                $span->end();
            }
        );

        /** @psalm-suppress UnusedFunctionCall */
        hook(
            HttpKernel::class,
            'handleThrowable',
            pre: static function (
                HttpKernel $_kernel,
                array $params,
                string $_class,
                string $_function,
                ?string $_filename,
                ?int $_lineno,
            ): array {
                /** @var \Throwable $throwable */
                $throwable = $params[0];

                Span::getCurrent()
                    ->recordException($throwable);

                return $params;
            },
        );

        /**
         * Extract symfony event dispatcher known events and trace the controller
         *
         * Adapted from https://github.com/DataDog/dd-trace-php/blob/master/src/DDTrace/Integrations/Symfony/SymfonyIntegration.php
         */
        hook(
            EventDispatcher::class,
            'dispatch',
            pre: static function (
                EventDispatcher $dispatcher,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): array {
                if (!isset($args[0])) {
                    return $params;
                }

                if (\is_object($args[0])) {
                    // dispatch($event, string $eventName = null)
                    $event = $args[0];
                    $eventName = isset($args[1]) && \is_string($args[1]) ? $args[1] : \get_class($event);
                } elseif (\is_string($args[0])) {
                    // dispatch($eventName, Event $event = null)
                    $eventName = $args[0];
                    $event = isset($args[1]) && \is_object($args[1]) ? $args[1] : null;
                } else {
                    return $params;
                }

                if ($eventName === 'kernel.controller' && \method_exists($event, 'getController')) {
                    $controller = $event->getController();
                    if (!($controller instanceof \Closure)) {
                        if (\is_callable($controller, false, $controllerName) && $controllerName !== null) {
                            if (\strpos($controllerName, '::') > 0) {
                                list($class, $method) = \explode('::', $controllerName);
                                if (isset($class, $method)) {
                                    hook(
                                        $class,
                                        $method,
                                        pre: static function (
                                            object $controller,
                                            array $params,
                                            string $class,
                                            string $function,
                                            ?string $filename,
                                            ?int $lineno,
                                        ) use ($instrumentation) {
                                            $parent = Context::getCurrent();
                                            $builder = $instrumentation
                                                ->tracer()
                                                ->spanBuilder(sprintf('%s::%s', $class, $function))
                                                ->setParent($parent);
                                            $span = $builder->startSpan();
                                            $parent = Context::getCurrent();
                                            Context::storage()->attach($span->storeInContext($parent));
                                        },
                                        post: static function (
                                            object $controller,
                                            array $params,
                                            $result,
                                            ?\Throwable $exception
                                        ) {
                                            $scope = Context::storage()->scope();
                                            if (null === $scope) {
                                                return;
                                            }

                                            $scope->detach();
                                            $span = Span::fromContext($scope->context());

                                            if (null !== $exception) {
                                                $span->recordException($exception, [
                                                    TraceAttributes::EXCEPTION_ESCAPED => true,
                                                ]);
                                                $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                                            }

                                            $span->end();
                                        }
                                    );
                                }
                            }
                        }
                    }
                }

                $parent = Context::getCurrent();
                $span = $instrumentation->tracer()
                    ->spanBuilder('symfony.' . $eventName)
                    ->setParent($parent)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext($parent));

                if ($event === null) {
                    return $params;
                }

                self::setControllerNameAsSpanName($event, $eventName);

                return $params;
            },
            post: static function (
                EventDispatcher $dispatcher,
                array $params,
                $result,
                ?\Throwable $exception
            ) use ($instrumentation): void {
                $scope = Context::storage()->scope();
                if (null === $scope) {
                    return;
                }

                $scope->detach();
                $span = Span::fromContext($scope->context());

                if (null !== $exception) {
                    $span->recordException($exception, [
                        TraceAttributes::EXCEPTION_ESCAPED => true,
                    ]);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
            }
        );
    }

    private static function setControllerNameAsSpanName($event, $eventName): void
    {
        if (
            !\defined("\Symfony\Component\HttpKernel\KernelEvents::CONTROLLER")
            || $eventName !== KernelEvents::CONTROLLER
            || !method_exists($event, 'getController')
        ) {
            return;
        }

        /** @var callable $controllerAndAction */
        $controllerAndAction = $event->getController();

        if (
            !is_array($controllerAndAction)
            || count($controllerAndAction) !== 2
            || !is_object($controllerAndAction[0])
        ) {
            return;
        }

        $action = get_class($controllerAndAction[0]) . '::' . $controllerAndAction[1];
        Span::getCurrent()->updateName($action);
    }
}
