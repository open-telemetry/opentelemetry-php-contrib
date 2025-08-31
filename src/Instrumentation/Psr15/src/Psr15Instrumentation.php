<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Psr15;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\Version;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * @psalm-suppress ArgumentTypeCoercion
 * @psalm-suppress UnusedClass
 */
class Psr15Instrumentation
{
    public const NAME = 'psr15';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.psr15',
            null,
            Version::VERSION_1_32_0->url(),
        );

        /**
         * Create a span for each psr-15 middleware that is executed.
         */
        /** @psalm-suppress UnusedFunctionCall */
        hook(
            MiddlewareInterface::class,
            'process',
            pre: static function (MiddlewareInterface $middleware, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $span = $instrumentation->tracer()->spanBuilder(sprintf('%s::%s', $class, $function))
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (MiddlewareInterface $middleware, array $params, ?ResponseInterface $response, ?Throwable $exception) {
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

        /**
         * Create a span to wrap RequestHandlerInterface::handle. The first execution is assumed to be the root span, which
         * is stored as a request attribute which may be accessed by later hooks.
         */
        /** @psalm-suppress UnusedFunctionCall */
        hook(
            RequestHandlerInterface::class,
            'handle',
            pre: static function (RequestHandlerInterface $handler, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $request = ($params[0] instanceof ServerRequestInterface) ? $params[0] : null;
                $root = $request
                    ? $request->getAttribute(SpanInterface::class)
                    : Span::getCurrent();
                $builder = $instrumentation->tracer()->spanBuilder(
                    $root
                    ? sprintf('%s::%s', $class, $function)
                    : sprintf('%s', $request?->getMethod() ?? 'unknown')
                )
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno);
                $parent = Context::getCurrent();
                if (!$root && $request) {
                    //create http root span
                    $parent = Globals::propagator()->extract($request->getHeaders());
                    $span = $builder
                        ->setParent($parent)
                        ->setAttribute(TraceAttributes::URL_FULL, $request->getUri()->__toString())
                        ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                        ->setAttribute(TraceAttributes::HTTP_REQUEST_BODY_SIZE, $request->getHeaderLine('Content-Length'))
                        ->setAttribute(TraceAttributes::URL_SCHEME, $request->getUri()->getScheme())
                        ->setAttribute(TraceAttributes::URL_PATH, $request->getUri()->getPath())
                        ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $request->getHeaderLine('User-Agent'))
                        ->setAttribute(TraceAttributes::SERVER_ADDRESS, $request->getUri()->getHost())
                        ->setAttribute(TraceAttributes::SERVER_PORT, $request->getUri()->getPort())
                        ->startSpan();
                    $request = $request->withAttribute(SpanInterface::class, $span);
                } else {
                    $span = $builder->setSpanKind(SpanKind::KIND_INTERNAL)->startSpan();
                }
                Context::storage()->attach($span->storeInContext($parent));

                return [$request];
            },
            post: static function (RequestHandlerInterface $handler, array $params, ?ResponseInterface $response, ?Throwable $exception) {
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
                if ($response) {
                    if ($response->getStatusCode() >= 400) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
                    $span->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $response->getProtocolVersion());
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_BODY_SIZE, $response->getHeaderLine('Content-Length'));
                }

                $span->end();
            }
        );
    }
}
