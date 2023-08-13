<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use function OpenTelemetry\Instrumentation\hook;

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
            HttpKernel::class,
            'handle',
            pre: static function (HttpKernel $kernel, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
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
                        ->setAttribute(TraceAttributes::NET_HOST_NAME, self::httpHostName($request))
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
            post: static function (HttpKernel $kernel, array $params, ?Response $response, ?Throwable $exception) {
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
            ConsoleKernel::class,
            'call',
            pre: static function (ConsoleKernel $kernel, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation->tracer()
                    ->spanBuilder(sprintf('Console %s', $params[0] ?? 'unknown'))
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);
                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            post: static function (ConsoleKernel $kernel, array $params, ?int $exitCode, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());
                if ($exception) {
                    $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                } elseif ($exitCode !== Command::SUCCESS) {
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }

                $span->end();
            }
        );

        hook(
            Application::class,
            '__construct',
            post: static function (Application $application, array $params, mixed $returnValue, ?Throwable $exception) use ($instrumentation) {
                self::registerWatchers($application, new ClientRequestWatcher($instrumentation));
                self::registerWatchers($application, new ExceptionWatcher());
                self::registerWatchers($application, new CacheWatcher());
                self::registerWatchers($application, new CommandWatcher());
                self::registerWatchers($application, new LogWatcher());
                self::registerWatchers($application, new QueryWatcher($instrumentation));
            },
        );
    }

    private static function httpTarget(Request $request): string
    {
        $query = $request->getQueryString();
        $question = $request->getBaseUrl() . $request->getPathInfo() === '/' ? '/?' : '?';

        return $query ? $request->path() . $question . $query : $request->path();
    }

    private static function httpHostName(Request $request): string
    {
        if (method_exists($request, 'host')) {
            return $request->host();
        }
        if (method_exists($request, 'getHost')) {
            return $request->getHost();
        }

        return '';
    }
}
