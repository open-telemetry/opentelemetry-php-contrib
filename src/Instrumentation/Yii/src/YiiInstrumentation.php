<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Yii;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use yii\base\InlineAction;
use yii\web\Application;
use yii\web\Controller;
use yii\web\Response;

class YiiInstrumentation
{
    public const NAME = 'yii';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.yii',
            null,
            'https://opentelemetry.io/schemas/1.30.0'
        );

        hook(
            Application::class,
            'handleRequest',
            pre: static function (
                Application $application,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation) : void {
                $request = $application->getRequest();
                $parent = Globals::propagator()->extract($request, RequestPropagationGetter::instance());

                /** @psalm-suppress ArgumentTypeCoercion */
                $spanBuilder = $instrumentation
                    ->tracer()
                    ->spanBuilder(sprintf('%s', $request->getMethod()))
                    ->setParent($parent)
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute(TraceAttributes::URL_FULL, $request->getAbsoluteUrl())
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_BODY_SIZE, $request->getHeaders()->get('Content-Length', null, true))
                    ->setAttribute(TraceAttributes::URL_SCHEME, $request->getIsSecureConnection() ? 'https' : 'http');

                $span = $spanBuilder->startSpan();

                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (
                Application $application,
                array $params,
                ?Response $response,
                ?\Throwable $exception
            ): void {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();

                $span = Span::fromContext($scope->context());

                if ($response) {
                    $statusCode = $response->getStatusCode();
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);
                    $span->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $response->version);
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_BODY_SIZE, YiiInstrumentation::getResponseLength($response));

                    $headers = $response->getHeaders();

                    foreach ((array) (get_cfg_var('otel.instrumentation.http.response_headers') ?: []) as $header) {
                        if ($headers->has($header)) {
                            /** @psalm-suppress ArgumentTypeCoercion */
                            $span->setAttribute(sprintf('http.response.header.%s', strtr(strtolower($header), ['-' => '_'])), $headers->get($header, null, true));
                        }
                    }

                    if ($statusCode >= 400 && $statusCode < 600) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }

                    // Propagate server-timing header to response, if ServerTimingPropagator is present
                    if (class_exists('OpenTelemetry\Contrib\Propagation\ServerTiming\ServerTimingPropagator')) {
                        /** @phan-suppress-next-line PhanUndeclaredClassMethod */
                        $prop = new \OpenTelemetry\Contrib\Propagation\ServerTiming\ServerTimingPropagator();
                        /** @phan-suppress-next-line PhanUndeclaredClassMethod */
                        $prop->inject($response, ResponsePropagationSetter::instance(), $scope->context());
                    }

                    // Propagate traceresponse header to response, if TraceResponsePropagator is present
                    if (class_exists('OpenTelemetry\Contrib\Propagation\TraceResponse\TraceResponsePropagator')) {
                        /** @phan-suppress-next-line PhanUndeclaredClassMethod */
                        $prop = new \OpenTelemetry\Contrib\Propagation\TraceResponse\TraceResponsePropagator();
                        /** @phan-suppress-next-line PhanUndeclaredClassMethod */
                        $prop->inject($response, ResponsePropagationSetter::instance(), $scope->context());
                    }
                }

                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
            }
        );

        hook(
            Controller::class,
            'beforeAction',
            pre: static function (
                Controller $controller,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) : void {
                $action = $params[0] ?? null;
                $scope = Context::storage()->scope();
                if (!$action || !$scope) {
                    return;
                }

                $span = Span::fromContext($scope->context());
                $actionName = $action instanceof InlineAction ? $action->actionMethod : $action->id;
                $route = YiiInstrumentation::normalizeRouteName(get_class($controller), $actionName);
                /** @psalm-suppress ArgumentTypeCoercion */
                $span->updateName($route);
                $span->setAttribute(TraceAttributes::HTTP_ROUTE, $route);
            },
            post: null
        );
    }

    protected static function getResponseLength(Response $response): ?string
    {
        $headerValue = $response->getHeaders()->get('Content-Length', null, true);
        if (is_string($headerValue)) {
            return $headerValue;
        }

        if ($response->content != null) {
            return (string) (strlen($response->content));
        }

        return null;
    }

    protected static function normalizeRouteName(string $controllerClassName, string $actionName): string
    {
        $lastSegment = strrchr($controllerClassName, '\\');

        if ($lastSegment === false) {
            return $controllerClassName . '.' . $actionName;
        }

        return substr($lastSegment, 1) . '.' . $actionName;
    }
}
