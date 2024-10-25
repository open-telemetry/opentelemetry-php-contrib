<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration;

use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\EventInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\Exception\InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Test\TestHttpServer;

final class HttpClientInstrumentationTest extends AbstractTest
{
    public static function setUpBeforeClass(): void
    {
        TestHttpServer::start();
    }

    protected function getHttpClient(string $testCase): HttpClientInterface
    {
        return new CurlHttpClient(['verify_peer' => false, 'verify_host' => false]);
    }

    /**
     * @dataProvider requestProvider
     */
    public function test_send_request(string $method, string $uri, int $statusCode, string $spanStatus): void
    {
        $client = $this->getHttpClient(__FUNCTION__);
        $this->assertCount(0, $this->storage);

        $response = $client->request($method, $uri, ['bindto' => '127.0.0.1:9876']);
        $response->getStatusCode();
        $this->assertCount(1, $this->storage);

        /** @var ImmutableSpan $span */
        $span = $this->storage[0];

        $this->assertStringContainsString($method, $span->getName());
        if ($method === 'GET') {
            $requestHeaders = $response->toArray(false);
            $this->assertArrayHasKey('HTTP_TRACEPARENT', $requestHeaders);
            $this->assertNotNull($requestHeaders['HTTP_TRACEPARENT']);
        }

        $this->assertTrue($span->getAttributes()->has(TraceAttributes::PEER_SERVICE));
        $this->assertSame(parse_url($uri)['host'] ?? null, $span->getAttributes()->get(TraceAttributes::PEER_SERVICE));
        $this->assertTrue($span->getAttributes()->has(TraceAttributes::URL_FULL));
        $this->assertSame($uri, $span->getAttributes()->get(TraceAttributes::URL_FULL));
        $this->assertTrue($span->getAttributes()->has(TraceAttributes::HTTP_REQUEST_METHOD));
        $this->assertSame($method, $span->getAttributes()->get(TraceAttributes::HTTP_REQUEST_METHOD));
        $this->assertTrue($span->getAttributes()->has(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertSame($spanStatus, $span->getStatus()->getCode());
        $this->assertSame($statusCode, $span->getAttributes()->get(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));
    }

    public function test_throw_exception(): void
    {
        $client = $this->getHttpClient(__FUNCTION__);
        $this->assertCount(0, $this->storage);

        try {
            $response = $client->request('GET', 'http://localhost:8057', [
                'bindto' => '127.0.0.1:9876',
                'auth_ntlm' => [],
            ]);
        } catch (InvalidArgumentException) {
        }

        $this->assertCount(1, $this->storage);

        /** @var ImmutableSpan $span */
        $span = $this->storage[0];
        /** @var EventInterface $event */
        $event = $span->getEvents()[0];

        $this->assertTrue($span->getAttributes()->has(TraceAttributes::URL_FULL));
        $this->assertTrue($span->getAttributes()->has(TraceAttributes::HTTP_REQUEST_METHOD));
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(InvalidArgumentException::class, $event->getAttributes()->get('exception.type'));
    }

    public function requestProvider(): array
    {
        return [
            ['GET', 'http://localhost:8057', Response::HTTP_OK, StatusCode::STATUS_UNSET],
            ['GET','http://localhost:8057/404', Response::HTTP_NOT_FOUND, StatusCode::STATUS_ERROR],
            ['POST','http://localhost:8057/json', Response::HTTP_OK, StatusCode::STATUS_UNSET],
            ['DELETE', 'http://localhost:8057/1', Response::HTTP_OK, StatusCode::STATUS_UNSET],
        ];
    }
}
