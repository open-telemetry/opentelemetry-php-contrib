<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Psr15;

use OpenTelemetry\API\Common\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * @psalm-suppress ArgumentTypeCoercion
 */
class Psr15Instrumentation
{
    public const ROOT_SPAN = '__psr15_root_span';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.psr15');

        /**
         * Create a span for each psr-15 middleware that is executed.
         */
        hook(
            MiddlewareInterface::class,
            'process',
            static function (MiddlewareInterface $middleware, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $span = $instrumentation->tracer()->spanBuilder(sprintf('%s::%s', $class, $function))
                    ->setAttribute('code.function', $function)
                    ->setAttribute('code.namespace', $class)
                    ->setAttribute('code.filepath', $filename)
                    ->setAttribute('code.lineno', $lineno)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            static function (MiddlewareInterface $middleware, array $params, ?ResponseInterface $response, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                $scope?->detach();
                if (!$scope) {
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

        /**
         * Create a span to wrap RequestHandlerInterface::handle
         * Stores the span as a request attribute which may be accessed by later hooks.
         */
        hook(
            RequestHandlerInterface::class,
            'handle',
            static function (RequestHandlerInterface $handler, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $request = ($params[0] instanceof ServerRequestInterface) ? $params[0] : null;
                $root = $instrumentation->tracer()->spanBuilder(sprintf('HTTP %s', $request?->getMethod() ?? 'unknown'))
                    ->setAttribute('code.function', $function)
                    ->setAttribute('code.namespace', $class)
                    ->setAttribute('code.filepath', $filename)
                    ->setAttribute('code.lineno', $lineno)
                    ->setAttribute(TraceAttributes::HTTP_URL, (string) $request?->getUri())
                    ->setAttribute(TraceAttributes::HTTP_METHOD, $request?->getMethod())
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_CONTENT_LENGTH, $request?->getHeaderLine('Content-Length'))
                    ->setAttribute(TraceAttributes::HTTP_SCHEME, $request?->getUri()?->getScheme())
                    ->startSpan();

                Context::storage()->attach($root->storeInContext(Context::getCurrent()));
                if ($request && $request instanceof ServerRequestInterface) {
                    $request = $request->withAttribute(SpanInterface::class, $root);

                    return [$request];
                }
            },
            static function (RequestHandlerInterface $handler, array $params, ?ResponseInterface $response, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                $scope?->detach();
                if (!$scope) {
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
    }
}
