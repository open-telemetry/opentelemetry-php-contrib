<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Curl\Unit;

use OpenTelemetry\Contrib\Instrumentation\Curl\CurlHandleMetadata;
use PHPUnit\Framework\TestCase;

class CurlHandleMetadataTest extends TestCase
{
    public function test_get_request_headers_to_send_deduplicates_already_propagated_header(): void
    {
        $metadata = new CurlHandleMetadata();

        // Simulate an upstream instrumentation (e.g. auto-guzzle/auto-psr18) having already
        // injected propagation headers into CURLOPT_HTTPHEADER before curl_exec() runs.
        $metadata->updateFromCurlOption(CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'traceparent: 00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-aaaaaaaaaaaaaaaa-01',
            'tracestate: foo=bar',
        ]);

        // Now the curl instrumentation injects its own propagation headers for the same context.
        $metadata->setHeaderToPropagate('traceparent', '00-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb-bbbbbbbbbbbbbbbb-01');
        $metadata->setHeaderToPropagate('tracestate', 'foo=bar');

        $headers = $metadata->getRequestHeadersToSend();

        $this->assertNotNull($headers);
        $this->assertCount(3, $headers);

        $traceparents = array_values(array_filter($headers, static fn ($h) => stripos($h, 'traceparent:') === 0));
        $tracestates = array_values(array_filter($headers, static fn ($h) => stripos($h, 'tracestate:') === 0));

        $this->assertCount(1, $traceparents);
        $this->assertCount(1, $tracestates);
        $this->assertSame('traceparent: 00-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb-bbbbbbbbbbbbbbbb-01', $traceparents[0]);
        $this->assertContains('Content-Type: application/json', $headers);
    }

    public function test_get_request_headers_to_send_returns_null_when_nothing_to_propagate(): void
    {
        $metadata = new CurlHandleMetadata();
        $metadata->updateFromCurlOption(CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $this->assertNull($metadata->getRequestHeadersToSend());
    }
}
