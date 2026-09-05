<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ClientRequestWatcher extends Watcher
{
    /**
     * @var array<string, list<SpanInterface>>
     */
    protected array $spans = [];

    public function __construct(
        private CachedInstrumentation $instrumentation,
    ) {
    }

    /**
     * @psalm-suppress UndefinedInterfaceMethod
     * @suppress PhanTypeArraySuspicious
     */
    public function register(Application $app): void
    {
        $app['events']->listen(RequestSending::class, [$this, 'recordRequest']);
        $app['events']->listen(ConnectionFailed::class, [$this, 'recordConnectionFailed']);
        $app['events']->listen(ResponseReceived::class, [$this, 'recordResponse']);
    }

    /**
     * @psalm-suppress ArgumentTypeCoercion
     * @psalm-suppress PossiblyUnusedMethod
     * @suppress PhanEmptyFQSENInCallable,PhanUndeclaredFunctionInCallable
     */
    public function recordRequest(RequestSending $request): void
    {
        $parsedUrl = collect(parse_url($request->request->url()) ?: []);
        $processedUrl = $parsedUrl->get('scheme', 'http') . '://' . $parsedUrl->get('host') . $parsedUrl->get('path', '');

        if ($parsedUrl->has('query')) {
            $processedUrl .= '?' . $parsedUrl->get('query');
        }
        $span = $this->instrumentation->tracer()->spanBuilder($request->request->method())
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttributes([
                HttpAttributes::HTTP_REQUEST_METHOD => $request->request->method(),
                UrlAttributes::URL_FULL => $processedUrl,
                UrlAttributes::URL_PATH => $parsedUrl['path'] ?? '',
                UrlAttributes::URL_SCHEME => $parsedUrl['scheme'] ?? '',
                ServerAttributes::SERVER_ADDRESS => $parsedUrl['host'] ?? '',
                ServerAttributes::SERVER_PORT => $parsedUrl['port'] ?? '',
            ])
            ->startSpan();
        $this->spans[$this->createRequestComparisonHash($request->request)][] = $span;
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function recordConnectionFailed(ConnectionFailed $request): void
    {
        $span = $this->shiftSpan($this->createRequestComparisonHash($request->request));
        if (null === $span) {
            return;
        }

        $span->setStatus(StatusCode::STATUS_ERROR, 'Connection failed');
        $span->end();
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function recordResponse(ResponseReceived $request): void
    {
        $span = $this->shiftSpan($this->createRequestComparisonHash($request->request));
        if (null === $span) {
            return;
        }

        $span->setAttributes([
            HttpAttributes::HTTP_RESPONSE_STATUS_CODE => $request->response->status(),
            HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE => $request->response->header('Content-Length'),
        ]);

        $this->maybeRecordError($span, $request->response);
        $span->end();
    }

    private function createRequestComparisonHash(Request $request): string
    {
        return sha1($request->method() . '|' . $request->url() . '|' . $request->body());
    }

    private function shiftSpan(string $requestHash): ?SpanInterface
    {
        if (empty($this->spans[$requestHash])) {
            return null;
        }

        $span = array_shift($this->spans[$requestHash]);

        if (empty($this->spans[$requestHash])) {
            unset($this->spans[$requestHash]);
        }

        return $span;
    }

    private function maybeRecordError(SpanInterface $span, Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        // HTTP status code 3xx is not really error
        // See https://www.rfc-editor.org/rfc/rfc9110.html#name-redirection-3xx
        if ($response->redirect()) {
            return;
        }

        $span->setStatus(
            StatusCode::STATUS_ERROR,
            HttpResponse::$statusTexts[$response->status()] ?? (string) $response->status()
        );
    }
}
