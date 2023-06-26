<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Request;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LaravelInstrumentation
{
    public const NAME = 'laravel';

    public static function registerWatchers(Application $app, Watcher $watcher)
    {
        $watcher->register($app);
    }

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.laravel');
        hook(
            Kernel::class,
            'handle',
            pre: static function (Kernel $kernel, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $request = ($params[0] instanceof Request) ? $params[0] : null;
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation->tracer()
                    ->spanBuilder(sprintf('HTTP %s', $request?->method() ?? 'unknown'))
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);
                $parent = Context::getCurrent();
                if ($request) {
                    $parent = Globals::propagator()->extract($request, HeadersPropagator::instance());
                    $span = $builder
                        ->setParent($parent)
                        ->setAttribute(TraceAttributes::HTTP_URL, $request->fullUrl())
                        ->setAttribute(TraceAttributes::HTTP_METHOD, $request->method())
                        ->setAttribute(TraceAttributes::HTTP_REQUEST_CONTENT_LENGTH, $request->header('Content-Length'))
                        ->setAttribute(TraceAttributes::HTTP_SCHEME, $request->getScheme())
                        ->setAttribute(TraceAttributes::HTTP_FLAVOR, $request->getProtocolVersion())
                        ->setAttribute(TraceAttributes::HTTP_CLIENT_IP, $request->ip())
                        ->setAttribute(TraceAttributes::HTTP_TARGET, self::httpTarget($request))
                        ->setAttribute(TraceAttributes::NET_HOST_NAME, $request->host())
                        ->setAttribute(TraceAttributes::NET_HOST_PORT, $request->getPort())
                        ->setAttribute(TraceAttributes::NET_PEER_PORT, $request->server('REMOTE_PORT'))
                        ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $request->userAgent())
                        ->startSpan();
                    $request->attributes->set(SpanInterface::class, $span);
                } else {
                    $span = $builder->startSpan();
                }
                Context::storage()->attach($span->storeInContext($parent));

                return [$request];
            },
            post: static function (Kernel $kernel, array $params, ?Response $response, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());
                if ($exception) {
                    $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }
                if ($response) {
                    if ($response->getStatusCode() >= 400) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }
                    $span->setAttribute(TraceAttributes::HTTP_STATUS_CODE, $response->getStatusCode());
                    $span->setAttribute(TraceAttributes::HTTP_FLAVOR, $response->getProtocolVersion());
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_CONTENT_LENGTH, $response->headers->get('Content-Length'));
                }

                $span->end();
            }
        );
        hook(
            Kernel::class,
            '__construct',
            pre: static function (Kernel $kernel, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $app = $params[0];
                $app->booted(static function (Application $app) use ($instrumentation) {
                    self::registerWatchers($app, new ClientRequestWatcher($instrumentation));
                    self::registerWatchers($app, new ExceptionWatcher());
                    self::registerWatchers($app, new CacheWatcher());
                    self::registerWatchers($app, new LogWatcher());
                    self::registerWatchers($app, new QueryWatcher($instrumentation));
                });
            },
            post: null
        );
    }

    private static function httpTarget(Request $request): string
    {
        $query = $request->getQueryString();
        $question = $request->getBaseUrl() . $request->getPathInfo() === '/' ? '/?' : '?';

        return $query ? $request->path() . $question . $query : $request->path();
    }
}
