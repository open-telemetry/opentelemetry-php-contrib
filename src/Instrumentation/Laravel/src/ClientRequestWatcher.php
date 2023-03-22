<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use OpenTelemetry\API\Common\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

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
            TraceAttributes::HTTP_STATUS_CODE => $request->response->status(),
            TraceAttributes::HTTP_RESPONSE_CONTENT_LENGTH => $request->response->header('Content-Length'),
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
