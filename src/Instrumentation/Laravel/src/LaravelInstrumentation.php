<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
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
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

class ClientRequestWatcher
{
    private CachedInstrumentation $instrumentation;
    /**
     * @var array<string, SpanInterface>
     */
    protected array $spans = [];

    public function __construct(CachedInstrumentation $instr)
    {
        $this->instrumentation = $instr;
    }
    public function recordRequest(RequestSending $request): void
    {
        $parsedUrl = collect(parse_url($request->request->url()));
        $processedUrl = $parsedUrl->get('scheme') . '://' . $parsedUrl->get('host') . $parsedUrl->get('path', '');

        if ($parsedUrl->has('query')) {
            $processedUrl .= '?' . $parsedUrl->get('query');
        }

        $span = $this->instrumentation->tracer()->spanBuilder('http ' . $request->request->method() . ' ' . $request->request->url())
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttributes([
                'http.method' => $request->request->method(),
                'http.url' => $processedUrl,
                'http.target' => $parsedUrl['path'] ?? '',
                'http.host' => $parsedUrl['host'] ?? '',
                'http.scheme' => $parsedUrl['scheme'] ?? '',
                'net.peer.name' => $parsedUrl['host'] ?? '',
                'net.peer.port' => $parsedUrl['port'] ?? '',
            ])
            ->startSpan();

        $this->spans[$this->createRequestComparisonHash($request->request)] = $span;
    }

    public function recordConnectionFailed(ConnectionFailed $request): void
    {
        $requestHash = $this->createRequestComparisonHash($request->request);

        $span = $this->spans[$requestHash] ?? null;
        if (null === $span) {
            return;
        }

        $span->setStatus(StatusCode::STATUS_ERROR, 'Connection failed');
        $span->end();

        unset($this->spans[$requestHash]);
    }

    public function recordResponse(ResponseReceived $request): void
    {
        $requestHash = $this->createRequestComparisonHash($request->request);

        $span = $this->spans[$requestHash] ?? null;
        if (null === $span) {
            return;
        }

        $span->setAttributes([
            'http.status_code' => $request->response->status(),
            'http.status_text' => HttpResponse::$statusTexts[$request->response->status()] ?? '',
            'http.response_content_length' => $request->response->header('Content-Length'),
            'http.response_content_type' => $request->response->header('Content-Type'),
        ]);

        $this->maybeRecordError($span, $request->response);
        $span->end();

        unset($this->spans[$requestHash]);
    }
    private function createRequestComparisonHash(\Illuminate\Http\Client\Request $request): string
    {
        return sha1($request->method() . '|' . $request->url() . '|' . $request->body());
    }

    private function maybeRecordError(SpanInterface $span, \Illuminate\Http\Client\Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $span->setStatus(
            StatusCode::STATUS_ERROR,
            HttpResponse::$statusTexts[$response->status()] ?? $response->status()
        );
    }
}

class LaravelInstrumentation
{
    private static $watchersInstalled = false;
    private static $application;

    public static function registerWatchers(Application $app, ClientRequestWatcher $watcher)
    {
        $app['events']->listen(RequestSending::class, [$watcher, 'recordRequest']);
        $app['events']->listen(ConnectionFailed::class, [$watcher, 'recordConnectionFailed']);
        $app['events']->listen(ResponseReceived::class, [$watcher, 'recordResponse']);
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
                    self::registerWatchers(self::$application, new ClientRequestWatcher($instrumentation));
                    self::$watchersInstalled = true;
                }
            },
            post: null
        );
        hook(
            ServiceProvider::class,
            '__construct',
            pre: static function (ServiceProvider $provider, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                self::$application = $params[0];
            },
            post: null
        );
    }
}
