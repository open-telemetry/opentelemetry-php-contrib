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
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ClientRequestWatcher extends Watcher
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
        $processedUrl = $parsedUrl->get('scheme') . '://' . $parsedUrl->get('host') . $parsedUrl->get('path', '');

        if ($parsedUrl->has('query')) {
            $processedUrl .= '?' . $parsedUrl->get('query');
        }
        $span = $this->instrumentation->tracer()->spanBuilder($request->request->method() ?? 'unknown')
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
            TraceAttributes::HTTP_RESPONSE_STATUS_CODE => $request->response->status(),
            TraceAttributes::HTTP_RESPONSE_BODY_SIZE => $request->response->header('Content-Length'),
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
