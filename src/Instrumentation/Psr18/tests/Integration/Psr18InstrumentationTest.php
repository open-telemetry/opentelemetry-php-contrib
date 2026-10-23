<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Psr18\Integration;

use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\TestUtils\TestState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

class Psr18InstrumentationTest extends TestCase
{
    use TestState;

    private ClientInterface&MockObject $client;

    public function setUp(): void
    {
        $this->client = $this->createMock(ClientInterface::class);
    }

    #[DataProvider('requestProvider')]
    public function test_send_request(string $method, string $uri, int $statusCode): void
    {
        $request = new Request(
            $method,
            $uri,
            [],
            'body',
            '1.1',
        );
        $response = new Response($statusCode);

        $this->assertCount(0, $this->spanStorage);
        $this->client
            ->expects($this->once())
            ->method('sendRequest')
            ->with($this->callback(function (RequestInterface $request) {
                $this->assertTrue($request->hasHeader('traceparent'), 'traceparent has been injected into request');
                $this->assertNotEmpty($request->getHeaderLine('traceparent'));

                return true;
            }))
            ->willReturn($response);
        $this->client->sendRequest($request);
        $this->assertCount(1, $this->spanStorage);

        /** @var ImmutableSpan $span */
        $span = $this->spanStorage[0];

        $this->assertStringContainsString($method, $span->getName());
        $this->assertTrue($span->getAttributes()->has(UrlAttributes::URL_FULL));
        $this->assertSame($uri, $span->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertTrue($span->getAttributes()->has(HttpAttributes::HTTP_REQUEST_METHOD));
        $this->assertSame($method, $span->getAttributes()->get(HttpAttributes::HTTP_REQUEST_METHOD));
        $this->assertTrue($span->getAttributes()->has(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertSame($statusCode, $span->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
    }

    public static function requestProvider(): array
    {
        return [
            'HTTP GET' => ['GET', 'http://example.com/foo', 200],
            'HTTPS POST' => ['POST', 'https://example.com/bar', 401],
        ];
    }

    #[DataProvider('requestHeadersEnvProvider')]
    public function test_capture_request_headers(string $envVar): void
    {
        putenv(sprintf('%s=x-custom-header,accept', $envVar));

        try {
            $request = new Request('GET', 'http://example.com/foo', ['x-custom-header' => 'my-value', 'accept' => 'application/json']);
            $this->client->method('sendRequest')->willReturn(new Response(200));
            $this->client->sendRequest($request);

            /** @var ImmutableSpan $span */
            $span = $this->spanStorage[0];
            $this->assertSame(['my-value'], $span->getAttributes()->get('http.request.header.x-custom-header'));
            $this->assertSame(['application/json'], $span->getAttributes()->get('http.request.header.accept'));
        } finally {
            putenv($envVar);
        }
    }

    public static function requestHeadersEnvProvider(): array
    {
        return [
            'standardized' => ['OTEL_INSTRUMENTATION_HTTP_CLIENT_CAPTURE_REQUEST_HEADERS'],
            'legacy' => ['OTEL_PHP_INSTRUMENTATION_HTTP_REQUEST_HEADERS'],
        ];
    }

    #[DataProvider('responseHeadersEnvProvider')]
    public function test_capture_response_headers(string $envVar): void
    {
        putenv(sprintf('%s=x-custom-header,content-type', $envVar));

        try {
            $request = new Request('GET', 'http://example.com/foo');
            $response = new Response(200, ['x-custom-header' => 'my-value', 'content-type' => 'application/json']);
            $this->client->method('sendRequest')->willReturn($response);
            $this->client->sendRequest($request);

            /** @var ImmutableSpan $span */
            $span = $this->spanStorage[0];
            $this->assertSame(['my-value'], $span->getAttributes()->get('http.response.header.x-custom-header'));
            $this->assertSame(['application/json'], $span->getAttributes()->get('http.response.header.content-type'));
        } finally {
            putenv($envVar);
        }
    }

    public static function responseHeadersEnvProvider(): array
    {
        return [
            'standardized' => ['OTEL_INSTRUMENTATION_HTTP_CLIENT_CAPTURE_RESPONSE_HEADERS'],
            'legacy' => ['OTEL_PHP_INSTRUMENTATION_HTTP_RESPONSE_HEADERS'],
        ];
    }
}
