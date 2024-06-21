<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\CodeIgniter;

use CodeIgniter\CodeIgniter;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;

class CodeIgniterInstrumentation
{
    public const NAME = 'codeigniter';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.codeigniter',
            null,
            'https://opentelemetry.io/schemas/1.24.0'
        );

        // The method that creates request/response/controller objects is in the same class as the method
        // that handles them, and they are not passed as method parameters, but rather stored in private
        // properties, thus reflection is required to read them.
        $reflectedIgniter = new \ReflectionClass(CodeIgniter::class);
        $requestProperty = $reflectedIgniter->getProperty('request');
        $requestProperty->setAccessible(true);
        $controllerProperty = $reflectedIgniter->getProperty('controller');
        $controllerProperty->setAccessible(true);
        $controllerMethodProperty = $reflectedIgniter->getProperty('method');
        $controllerMethodProperty->setAccessible(true);

        hook(
            CodeIgniter::class,
            'handleRequest',
            pre: static function (
                CodeIgniter $igniter,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation, $requestProperty): void {
                $extractedRequest = $requestProperty->getValue($igniter);
                $request = ($extractedRequest instanceof RequestInterface) ? $extractedRequest : null;

                /** @psalm-suppress ArgumentTypeCoercion,DeprecatedMethod */
                $spanBuilder = $instrumentation
                    ->tracer()
                    /** @phan-suppress-next-line PhanDeprecatedFunction */
                    ->spanBuilder(\sprintf('%s', $request?->getMethod() ?? 'unknown'))
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);

                $parent = Context::getCurrent();
                
                if ($request) {
                    $parent = Globals::propagator()->extract($request, RequestPropagationGetter::instance());

                    $spanBuilder = $spanBuilder->setParent($parent)
                        ->setAttribute(TraceAttributes::URL_FULL, (string) $request->getUri())
                        ->setAttribute(TraceAttributes::URL_PATH, $request->getUri()->getPath())
                        /** @phan-suppress-next-line PhanDeprecatedFunction */
                        ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                        ->setAttribute(TraceAttributes::HTTP_REQUEST_BODY_SIZE, $request->getHeaderLine('Content-Length'))
                        ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $request->getHeaderLine('User-Agent'))
                        ->setAttribute(TraceAttributes::SERVER_ADDRESS, $request->getUri()->getHost())
                        ->setAttribute(TraceAttributes::SERVER_PORT, $request->getUri()->getPort())
                        ->setAttribute(TraceAttributes::URL_SCHEME, $request->getUri()->getScheme());
                }

                $span = $spanBuilder->startSpan();

                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (
                CodeIgniter $igniter,
                array $params,
                ?ResponseInterface $response,
                ?\Throwable $exception
            ) use ($controllerProperty, $controllerMethodProperty): void {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();

                $span = Span::fromContext($scope->context());

                if ($response) {
                    /** @psalm-suppress DeprecatedMethod */
                    /** @phan-suppress-next-line PhanDeprecatedFunction */
                    $statusCode = $response->getStatusCode();
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);
                    $span->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $response->getProtocolVersion());
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_BODY_SIZE, CodeIgniterInstrumentation::getResponseLength($response));

                    foreach ((array) (get_cfg_var('otel.instrumentation.http.response_headers') ?: []) as $header) {
                        if ($response->hasHeader($header)) {
                            /** @psalm-suppress ArgumentTypeCoercion */
                            $span->setAttribute(sprintf('http.response.header.%s', strtolower($header)), $response->getHeaderLine($header));
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
                
                $controller = $controllerProperty->getValue($igniter);
                $controllerClassName = CodeIgniterInstrumentation::getControllerClassName($controller);
                $controllerMethod = $controllerMethodProperty->getValue($igniter);

                if ($controllerClassName !== null && is_string($controllerMethod)) {
                    $routeName = CodeIgniterInstrumentation::normalizeRouteName($controllerClassName, $controllerMethod);
                    $span->setAttribute(TraceAttributes::HTTP_ROUTE, $routeName);
                }

                if ($exception) {
                    $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
            }
        );
    }

    protected static function getControllerClassName(mixed $controller): ?string
    {
        if (is_object($controller)) {
            return get_class($controller);
        } elseif (is_string($controller)) {
            return $controller;
        }

        return null;
    }

    protected static function normalizeRouteName(string $controllerClassName, string $controllerMethod): string
    {
        $lastSegment = strrchr($controllerClassName, '\\');
        
        if ($lastSegment === false) {
            return $controllerClassName . '.' . $controllerMethod;
        }

        return substr($lastSegment, 1) . '.' . $controllerMethod;
    }

    protected static function getResponseLength(ResponseInterface $response): int
    {
        if ($response instanceof DownloadResponse) {
            return $response->getContentLength();
        }
        $body = $response->getBody();

        if (is_string($body)) {
            return strlen($body);
        }

        return 0;
    }
}
