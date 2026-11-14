<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration;

use ApiPlatform\Symfony\Bundle\Test\Client as ApiPlatformTestClient;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\Fixtures\ForwardingHttpClient;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\Exception\InvalidArgumentException;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Component\HttpClient\UriTemplateHttpClient;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\Test\TestHttpServer;

require_once __DIR__ . '/Fixtures/ApiPlatform/Symfony/Bundle/Test/Client.php';

final class HttpClientInstrumentationTest extends AbstractTest
{
    public static function setUpBeforeClass(): void
    {
        TestHttpServer::start();
    }

    protected function getHttpClient(string $_testCase): HttpClientInterface
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

        $span = $this->storage[0];

        $this->assertStringContainsString($method, $span->getName());
        if ($method === 'GET') {
            $requestHeaders = $response->toArray(false);
            $this->assertArrayHasKey('HTTP_TRACEPARENT', $requestHeaders);
            $this->assertNotNull($requestHeaders['HTTP_TRACEPARENT']);
        }

        $this->assertTrue($span->getAttributes()->has(TraceAttributes::PEER_SERVICE));
        $this->assertSame(parse_url($uri)['host'] ?? null, $span->getAttributes()->get(TraceAttributes::PEER_SERVICE));
        $this->assertTrue($span->getAttributes()->has(UrlAttributes::URL_FULL));
        $this->assertSame($uri, $span->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertTrue($span->getAttributes()->has(HttpAttributes::HTTP_REQUEST_METHOD));
        $this->assertSame($method, $span->getAttributes()->get(HttpAttributes::HTTP_REQUEST_METHOD));
        $this->assertTrue($span->getAttributes()->has(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertSame($spanStatus, $span->getStatus()->getCode());
        $this->assertSame($statusCode, $span->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
    }

    public function test_throw_exception(): void
    {
        $client = $this->getHttpClient(__FUNCTION__);
        $this->assertCount(0, $this->storage);

        try {
            $client->request('GET', 'http://localhost:8057', [
                'bindto' => '127.0.0.1:9876',
                'auth_ntlm' => [],
            ]);
        } catch (InvalidArgumentException) {
        }

        $this->assertCount(1, $this->storage);

        $span = $this->storage[0];
        $event = $span->getEvents()[0];

        $this->assertTrue($span->getAttributes()->has(UrlAttributes::URL_FULL));
        $this->assertTrue($span->getAttributes()->has(HttpAttributes::HTTP_REQUEST_METHOD));
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(InvalidArgumentException::class, $event->getAttributes()->get('exception.type'));
    }

    public function test_decorated_client_creates_a_single_span(): void
    {
        $client = new ForwardingHttpClient(new ForwardingHttpClient($this->getHttpClient(__FUNCTION__)));
        $this->assertCount(0, $this->storage);

        $response = $client->request('GET', 'http://localhost:8057');
        $requestHeaders = $response->toArray(false);
        $this->assertCount(1, $this->storage);

        $span = $this->storage[0];
        $this->assertSame('http://localhost:8057', $span->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame(200, $span->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertStringContainsString($span->getSpanId(), $requestHeaders['HTTP_TRACEPARENT']);
    }

    public function test_decorated_client_exception_is_recorded_once(): void
    {
        $client = new ForwardingHttpClient($this->getHttpClient(__FUNCTION__));
        $this->assertCount(0, $this->storage);

        try {
            $client->request('GET', 'http://localhost:8057', ['auth_ntlm' => []]);
        } catch (InvalidArgumentException) {
        }

        $this->assertCount(1, $this->storage);
        $this->assertCount(1, $this->storage[0]->getEvents());
        $this->assertSame(StatusCode::STATUS_ERROR, $this->storage[0]->getStatus()->getCode());
    }

    public function test_decorator_issuing_another_request_creates_its_own_span(): void
    {
        $client = new ForwardingHttpClient(
            $this->getHttpClient(__FUNCTION__),
            static function (HttpClientInterface $inner): void {
                $inner->request('POST', 'http://localhost:8057/json')->getStatusCode();
            },
        );
        $this->assertCount(0, $this->storage);

        $client->request('GET', 'http://localhost:8057')->getStatusCode();
        $this->assertCount(2, $this->storage);

        $this->assertSame('http://localhost:8057/json', $this->storage[0]->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame('http://localhost:8057', $this->storage[1]->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame($this->storage[1]->getSpanId(), $this->storage[0]->getParentSpanId());
    }

    public function test_client_resolving_a_relative_url_creates_a_single_span(): void
    {
        $client = new ForwardingHttpClient(ScopingHttpClient::forBaseUri(
            new ForwardingHttpClient($this->getHttpClient(__FUNCTION__)),
            'http://localhost:8057/',
        ));
        $this->assertCount(0, $this->storage);

        $response = $client->request('GET', '/?a=a&b=b');
        $requestHeaders = $response->toArray(false);
        $this->assertCount(1, $this->storage);

        $span = $this->storage[0];
        $this->assertSame('http://localhost:8057/?a=a&b=b', $span->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame('localhost', $span->getAttributes()->get(TraceAttributes::PEER_SERVICE));
        $this->assertSame(200, $span->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertStringContainsString($span->getSpanId(), $requestHeaders['HTTP_TRACEPARENT']);
    }

    public function test_client_resolving_a_relative_path_against_a_base_path_creates_a_single_span(): void
    {
        $client = ScopingHttpClient::forBaseUri($this->getHttpClient(__FUNCTION__), 'http://localhost:8057/json/');
        $this->assertCount(0, $this->storage);

        $client->request('GET', '1', ['query' => ['a' => 'a']])->getStatusCode();
        $this->assertCount(1, $this->storage);

        $span = $this->storage[0];
        $this->assertSame('http://localhost:8057/json/1?a=a', $span->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame(200, $span->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
    }

    public function test_decorator_issuing_another_absolute_request_for_a_relative_one_creates_its_own_span(): void
    {
        $client = new ForwardingHttpClient(
            ScopingHttpClient::forBaseUri($this->getHttpClient(__FUNCTION__), 'http://localhost:8057/'),
            static function (HttpClientInterface $inner): void {
                $inner->request('GET', 'http://localhost:8057/json')->getStatusCode();
            },
        );
        $this->assertCount(0, $this->storage);

        $client->request('GET', '/')->getStatusCode();
        $this->assertCount(2, $this->storage);

        $this->assertSame('http://localhost:8057/json', $this->storage[0]->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame('http://localhost:8057/', $this->storage[1]->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame($this->storage[1]->getSpanId(), $this->storage[0]->getParentSpanId());
    }

    public function test_decorator_issuing_a_request_for_the_same_path_on_another_host_creates_its_own_span(): void
    {
        $client = new ForwardingHttpClient(
            ScopingHttpClient::forBaseUri($this->getHttpClient(__FUNCTION__), 'http://localhost:8057/'),
            static function (HttpClientInterface $inner): void {
                $inner->request('GET', 'http://127.0.0.1:8057/')->getStatusCode();
            },
        );
        $this->assertCount(0, $this->storage);

        $client->request('GET', '/')->getStatusCode();
        $this->assertCount(2, $this->storage);

        $this->assertSame('http://127.0.0.1:8057/', $this->storage[0]->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame('http://localhost:8057/', $this->storage[1]->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame('localhost', $this->storage[1]->getAttributes()->get(TraceAttributes::PEER_SERVICE));
        $this->assertSame($this->storage[1]->getSpanId(), $this->storage[0]->getParentSpanId());
    }

    public function test_decorator_below_a_rewriting_client_issuing_another_request_creates_its_own_span(): void
    {
        $client = ScopingHttpClient::forBaseUri(
            new ForwardingHttpClient(
                $this->getHttpClient(__FUNCTION__),
                static function (HttpClientInterface $inner): void {
                    $inner->request('POST', 'http://localhost:8057/post')->getStatusCode();
                },
            ),
            'http://localhost:8057/',
        );
        $this->assertCount(0, $this->storage);

        $client->request('GET', '/json')->getStatusCode();
        $this->assertCount(2, $this->storage);

        $this->assertSame('http://localhost:8057/post', $this->storage[0]->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame('http://localhost:8057/json', $this->storage[1]->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame($this->storage[1]->getSpanId(), $this->storage[0]->getParentSpanId());
    }

    public function test_client_merging_the_query_option_into_an_absolute_url_creates_a_single_span(): void
    {
        $client = ScopingHttpClient::forBaseUri($this->getHttpClient(__FUNCTION__), 'http://localhost:8057/');
        $this->assertCount(0, $this->storage);

        $client->request('GET', 'http://localhost:8057/json', ['query' => ['a' => 'a']])->getStatusCode();
        $this->assertCount(1, $this->storage);
        $this->assertSame('http://localhost:8057/json?a=a', $this->storage[0]->getAttributes()->get(UrlAttributes::URL_FULL));
    }

    public function test_client_expanding_a_uri_template_creates_a_single_span(): void
    {
        $client = new UriTemplateHttpClient(
            ScopingHttpClient::forBaseUri($this->getHttpClient(__FUNCTION__), 'http://localhost:8057/'),
            static fn (string $url, array $vars): string => str_replace('{id}', (string) $vars['id'], $url),
        );
        $this->assertCount(0, $this->storage);

        $client->request('GET', '/json/{id}', ['vars' => ['id' => 1]])->getStatusCode();
        $this->assertCount(1, $this->storage);

        $span = $this->storage[0];
        $this->assertSame('http://localhost:8057/json/1', $span->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame('localhost', $span->getAttributes()->get(TraceAttributes::PEER_SERVICE));
        $this->assertSame(200, $span->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
    }

    public function test_span_activated_between_decorators_does_not_receive_the_resolved_url(): void
    {
        $client = new ForwardingHttpClient(new class(ScopingHttpClient::forBaseUri($this->getHttpClient(__FUNCTION__), 'http://localhost:8057/')) implements HttpClientInterface {
            use DecoratorTrait;

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                $span = Globals::tracerProvider()->getTracer('test')->spanBuilder('decorator')->startSpan();
                $scope = $span->activate();

                try {
                    return $this->client->request($method, $url, $options);
                } finally {
                    $scope->detach();
                    $span->end();
                }
            }
        });
        $this->assertCount(0, $this->storage);

        $client->request('GET', '/json')->getStatusCode();
        $this->assertCount(2, $this->storage);

        $this->assertNull($this->storage[0]->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame('http://localhost:8057/json', $this->storage[1]->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame('localhost', $this->storage[1]->getAttributes()->get(TraceAttributes::PEER_SERVICE));
    }

    public function test_synchronous_client_does_not_receive_on_progress_option(): void
    {
        $client = new ApiPlatformTestClient();

        $client->request('GET', 'http://localhost:8057');

        $this->assertArrayNotHasKey('on_progress', $client->options);
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
