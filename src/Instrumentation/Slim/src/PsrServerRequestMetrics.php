<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Slim;

use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\SemConv\Metrics\HttpMetrics;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class PsrServerRequestMetrics
{
    private static ?HistogramInterface $serverRequestDuration;

    /**
     * Generate HTTP Server Request metrics.
     * It implements the stable http.server.request.duration metric, along with required and recommended attributes as of SemConv 1.34.0
     *
     * @see https://opentelemetry.io/docs/specs/semconv/http/http-metrics/#metric-httpserverrequestduration
     */
    public static function generate(MeterInterface $meter, ServerRequestInterface $request, ?ResponseInterface $response, ?Throwable $exception): void
    {
        self::$serverRequestDuration ??= $meter->createHistogram(HttpMetrics::HTTP_SERVER_REQUEST_DURATION, 's', 'Duration of HTTP server requests');

        $startTime = $request->getServerParams()['REQUEST_TIME_FLOAT'] ?? null;
        if ($startTime === null) {
            // without start time, we cannot measure the request duration
            return;
        }

        if (self::$serverRequestDuration->isEnabled()) {
            $duration = (microtime(true) - (float) $startTime);
            $attributes = [
                TraceAttributes::HTTP_REQUEST_METHOD => $request->getMethod(),
                TraceAttributes::URL_SCHEME => $request->getUri()->getScheme(),
            ];
            if ($response && $response->getStatusCode() >= 500) {
                //@see https://opentelemetry.io/docs/specs/semconv/http/http-spans/#status
                $attributes[TraceAttributes::EXCEPTION_TYPE] = (string) $response->getStatusCode();
            } elseif ($exception) {
                $attributes[TraceAttributes::EXCEPTION_TYPE] = $exception::class;
            }
            if ($response) {
                $attributes[TraceAttributes::HTTP_RESPONSE_BODY_SIZE] = (int) $response->getHeaderLine('Content-Length');
                $attributes[TraceAttributes::NETWORK_PROTOCOL_VERSION] = $response->getProtocolVersion();
                $attributes[TraceAttributes::HTTP_RESPONSE_STATUS_CODE] = $response->getStatusCode();
            }
            /** @psalm-suppress PossiblyInvalidArgument */
            self::$serverRequestDuration->record($duration, $attributes);
        }
    }

    /**
     * @internal
     */
    public static function reset(): void
    {
        self::$serverRequestDuration = null;
    }
}
