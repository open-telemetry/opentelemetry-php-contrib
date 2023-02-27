<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Symfony;

use OpenTelemetry\API\Common\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Common\Instrumentation\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernel;
use Throwable;

final class SymfonyInstrumentation
{
    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.symfony');
        hook(
            HttpKernel::class,
            'handle',
            pre: static function (HttpKernel $kernel, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $request = ($params[0] instanceof Request) ? $params[0] : null;
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation->tracer()
                    ->spanBuilder(sprintf('HTTP %s', $request?->getMethod() ?? 'unknown'))
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute('code.function', $function)
                    ->setAttribute('code.namespace', $class)
                    ->setAttribute('code.filepath', $filename)
                    ->setAttribute('code.lineno', $lineno);
                $parent = Context::getCurrent();
                if ($request) {
                    $parent = Globals::propagator()->extract($request, HeadersPropagator::instance());
                    $span = $builder
                        ->setParent($parent)
                        ->setAttribute(TraceAttributes::HTTP_URL, $request->getUri())
                        ->setAttribute(TraceAttributes::HTTP_METHOD, $request->getMethod())
                        ->setAttribute(TraceAttributes::HTTP_REQUEST_CONTENT_LENGTH, $request->headers->get('Content-Length'))
                        ->setAttribute(TraceAttributes::HTTP_SCHEME, $request->getScheme())
                        ->startSpan();
                    $request->attributes->set(SpanInterface::class, $span);
                } else {
                    $span = $builder->startSpan();
                }
                Context::storage()->attach($span->storeInContext($parent));

                return [$request];
            },
            post: static function (HttpKernel $kernel, array $params, ?Response $response, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());

                $request = ($params[0] instanceof Request) ? $params[0] : null;
                if (null !== $request) {
                    $routeName = $request->attributes->get('_route', '');

                    if ('' !== $routeName) {
                        $span->setAttribute(TraceAttributes::HTTP_ROUTE, $routeName);
                    }
                }

                if ($exception) {
                    $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }
                if ($response) {
                    if ($response->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }
                    $span->setAttribute(TraceAttributes::HTTP_STATUS_CODE, $response->getStatusCode());
                    $span->setAttribute(TraceAttributes::HTTP_FLAVOR, $response->getProtocolVersion());
                    $contentLength = $response->headers->get('Content-Length');
                    /** @psalm-suppress PossiblyFalseArgument */
                    if (null === $contentLength && is_string($response->getContent())) {
                        $contentLength = \strlen($response->getContent());
                    }

                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_CONTENT_LENGTH, $contentLength);
                }

                $span->end();
            }
        );
    }
}
