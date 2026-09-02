<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Curl\Unit;

use OpenTelemetry\Contrib\Instrumentation\Curl\CurlHandleMetadata;
use PHPUnit\Framework\TestCase;

class CurlHandleMetadataTest extends TestCase
{
    public function test_get_request_headers_to_send_replaces_propagated_headers_only(): void
    {
        $metadata = new CurlHandleMetadata();

        // Simulate an upstream instrumentation (e.g. auto-guzzle/auto-psr18) having already
        // injected propagation headers into CURLOPT_HTTPHEADER before curl_exec() runs.
        $metadata->updateFromCurlOption(CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Custom: first',
            'X-Custom: second',
            'traceparent: 00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-aaaaaaaaaaaaaaaa-01',
            'tracestate: upstream=existing',
        ]);

        // Now the curl instrumentation injects its own propagation headers for the same context.
        $metadata->setHeaderToPropagate('traceparent', '00-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb-bbbbbbbbbbbbbbbb-01');
        $metadata->setHeaderToPropagate('tracestate', 'otel=injected');

        $headers = $metadata->getRequestHeadersToSend();

        $this->assertNotNull($headers);
        $this->assertSame([
            'Content-Type: application/json',
            'X-Custom: first',
            'X-Custom: second',
            'traceparent: 00-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb-bbbbbbbbbbbbbbbb-01',
            'tracestate: otel=injected',
        ], $headers);
    }

    public function test_get_request_headers_to_send_returns_null_when_nothing_to_propagate(): void
    {
        $metadata = new CurlHandleMetadata();
        $metadata->updateFromCurlOption(CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $this->assertNull($metadata->getRequestHeadersToSend());
    }
}
