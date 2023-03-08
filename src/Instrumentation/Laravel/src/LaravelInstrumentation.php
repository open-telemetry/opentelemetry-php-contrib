<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Common\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Common\Instrumentation\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

class LaravelInstrumentation
{
    private static $watchersInstalled = false;
    private static $application;
    public static function registerWatchers(Application $app)
    {
        $app['events']->listen(RequestSending::class, ['recordRequest']);
        $app['events']->listen(ConnectionFailed::class, ['recordConnectionFailed']);
        $app['events']->listen(ResponseReceived::class, ['recordResponse']);
    }

    public function recordRequest(RequestSending $request): void
    {
        $name = 'recordRequest';
        $builder = $instrumentation->tracer()->spanBuilder($name)
            ->setAttribute('code.function', $function)
            ->setAttribute('code.namespace', $class)
            ->setAttribute('code.filepath', $filename)
            ->setAttribute('code.lineno', $lineno);
        $span = $builder->startSpan();
        Context::storage()->attach($span->storeInContext(Context::getCurrent()));
        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }
        $scope->detach();
        $span->end();
    }

    public function recordConnectionFailed(ConnectionFailed $request): void
    {
    }

    public function recordResponse(ResponseReceived $request): void
    {
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
                    ->setAttribute('code.function', $function)
                    ->setAttribute('code.namespace', $class)
                    ->setAttribute('code.filepath', $filename)
                    ->setAttribute('code.lineno', $lineno);
                $parent = Context::getCurrent();
                if ($request) {
                    $parent = Globals::propagator()->extract($request, HeadersPropagator::instance());
                    $span = $builder
                        ->setParent($parent)
                        ->setAttribute(TraceAttributes::HTTP_URL, $request->url())
                        ->setAttribute(TraceAttributes::HTTP_METHOD, $request->method())
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
            ServiceProvider::class,
            'boot',
            pre: static function (ServiceProvider $provider, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                if (!self::$watchersInstalled) {
                    self::$watchersInstalled = true;
                    self::registerWatchers(self::$application);
                    $name = 'ServiceProvider::boot';
                    $builder = $instrumentation->tracer()->spanBuilder($name)
                        ->setAttribute('code.function', $function)
                        ->setAttribute('code.namespace', $class)
                        ->setAttribute('code.filepath', $filename)
                        ->setAttribute('code.lineno', $lineno);
                    $span = $builder->startSpan();
                    Context::storage()->attach($span->storeInContext(Context::getCurrent()));
                    $scope = Context::storage()->scope();
                    if (!$scope) {
                        return;
                    }
                    $scope->detach();
                    $span->end();
                }
            },
            post: static function (ServiceProvider $provider, array $params, null $ret, ?Throwable $exception) {
            }
        );
        hook(
            ServiceProvider::class,
            '__construct',
            pre: static function (ServiceProvider $provider, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                {
                    $name = 'ServiceProvider::__construct:' . spl_object_id($params[0]);
                    $builder = $instrumentation->tracer()->spanBuilder($name)
                        ->setAttribute('code.function', $function)
                        ->setAttribute('code.namespace', $class)
                        ->setAttribute('code.filepath', $filename)
                        ->setAttribute('code.lineno', $lineno);
                    $span = $builder->startSpan();
                    Context::storage()->attach($span->storeInContext(Context::getCurrent()));
                    $scope = Context::storage()->scope();
                    if (!$scope) {
                        return;
                    }
                    $scope->detach();
                    $span->end();
                }
            },
            post: static function (ServiceProvider $provider, array $params, null $ret, ?Throwable $exception) {
                self::$application = $params[0];
            }
        );
    }
}
