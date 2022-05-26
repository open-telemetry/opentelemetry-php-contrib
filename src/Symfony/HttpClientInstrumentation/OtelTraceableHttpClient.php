<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\HttpClientInstrumentation;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

class OtelTraceableHttpClient implements HttpClientInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private HttpClientInterface $httpClient;

    private TracerInterface $tracer;

    private TextMapPropagatorInterface $propagator;

    public function __construct(HttpClientInterface $httpClient, TracerInterface $tracer, TextMapPropagatorInterface $propagator)
    {
        $this->httpClient = $httpClient;
        $this->tracer = $tracer;
        $this->propagator = $propagator;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $spanName = sprintf('%s %s', $method, $url);
        $sb = $this->tracer->spanBuilder($spanName);
        $span = $sb->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttributes([
                TraceAttributes::HTTP_URL => $url,
                TraceAttributes::HTTP_METHOD => $method,
            ])
            ->startSpan();

        $span->activate();

        if (!array_key_exists('headers', $options)) {
            $options['headers'] = [];
        }

        $this->propagator->inject($options['headers']);

        try {
            /** @var ResponseInterface $response */
            $response = $this->httpClient->request($method, $url, $options);

            $span
                ->setStatus(StatusCode::STATUS_OK)
                ->setAttribute(TraceAttributes::HTTP_STATUS_CODE, $response->getStatusCode());

            if ($response->getStatusCode() >= 500) {
                $span->setStatus(StatusCode::STATUS_ERROR);
            }
        } catch (\Exception $e) {
            $span
                ->setStatus(StatusCode::STATUS_ERROR)
                ->recordException($e)
                ->end();

            // re-throw :)
            throw $e;
        } finally {
            $span
                ->end();

            return $response;
        }
    }

    public function stream($responses, float $timeout = null): ResponseStreamInterface
    {
        return $this->httpClient->stream($responses, $timeout);
    }
}
