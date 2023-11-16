<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\TraceAttributes;
use SplObjectStorage;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ClientRequestWatcher extends Watcher
{
    /** @var SplObjectStorage<\Illuminate\Http\Client\Request, SpanInterface> */
    protected SplObjectStorage $spans;

    public function __construct(
        private CachedInstrumentation $instrumentation,
    ) {
        $this->spans = new SplObjectStorage();
    }

    /** @psalm-suppress UndefinedInterfaceMethod */
    public function register(Application $app): void
    {
        $app['events']->listen(RequestSending::class, [$this, 'recordRequest']);
        $app['events']->listen(ConnectionFailed::class, [$this, 'recordConnectionFailed']);
        $app['events']->listen(ResponseReceived::class, [$this, 'recordResponse']);
    }

    /**
     * @psalm-suppress ArgumentTypeCoercion
     */
    public function recordRequest(RequestSending $request): void
    {
        $parsedUrl = collect(parse_url($request->request->url()));
        $processedUrl = $parsedUrl->get('scheme', 'http') . '://' . $parsedUrl->get('host') . $parsedUrl->get('path', '');

        if ($parsedUrl->has('query')) {
            $processedUrl .= '?' . $parsedUrl->get('query');
        }
        $span = $this->instrumentation->tracer()->spanBuilder($request->request->method())
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttributes([
                TraceAttributes::HTTP_REQUEST_METHOD => $request->request->method(),
                TraceAttributes::URL_FULL => $processedUrl,
                TraceAttributes::URL_PATH => $parsedUrl['path'] ?? '',
                TraceAttributes::URL_SCHEME => $parsedUrl['scheme'] ?? '',
                TraceAttributes::SERVER_ADDRESS => $parsedUrl['host'] ?? '',
                TraceAttributes::SERVER_PORT => $parsedUrl['port'] ?? '',
            ])
            ->startSpan();

        $this->spans[$request->request] = $span;
    }

    public function recordConnectionFailed(ConnectionFailed $request): void
    {
        $span = $this->spans[$request->request] ?? null;
        if (null === $span) {
            return;
        }

        $span->setStatus(StatusCode::STATUS_ERROR, 'Connection failed');
        $span->end();

        unset($this->spans[$request->request]);
    }

    public function recordResponse(ResponseReceived $request): void
    {
        $span = $this->spans[$request->request] ?? null;
        if (null === $span) {
            return;
        }

        $span->setAttributes([
            TraceAttributes::HTTP_RESPONSE_STATUS_CODE => $request->response->status(),
            TraceAttributes::HTTP_RESPONSE_BODY_SIZE => $request->response->header('Content-Length'),
        ]);

        $this->maybeRecordError($span, $request->response);
        $span->end();

        unset($this->spans[$request->request]);
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
