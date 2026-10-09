<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\HttpAsyncClient\tests\Integration;

use ArrayObject;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Promise\Utils;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * @covers \OpenTelemetry\Contrib\Instrumentation\Guzzle\GuzzleInstrumentation
 */
class GuzzleInstrumentationTest extends TestCase
{
    private Client $client;
    private HandlerStack $handlerStack;
    private MockHandler $mock;
    private ScopeInterface $scope;
    private ArrayObject $storage;

    public function setUp(): void
    {
        $this->mock = new MockHandler();
        $this->handlerStack = HandlerStack::create($this->mock);
        $this->client = new Client([
            'handler' => $this->handlerStack,
            'base_uri' => 'https://example.com/',
            'http_errors' => false,
        ]);

        $this->storage = new ArrayObject();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage)
            )
        );

        $this->scope = Configurator::create()
            ->withTracerProvider($tracerProvider)
            ->withPropagator(TraceContextPropagator::getInstance())
            ->activate();
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    public function test_send_with_options(): void
    {
        $this->mock->append(new Response());
        $this->assertCount(0, $this->storage);
        $request = new Request('GET', 'https://example.com/foo');
        $this->client->send($request, [
            'connect_timeout' => 3.14,
        ]);

        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        assert($span instanceof ImmutableSpan);
        $this->assertSame('example.com', $span->getAttributes()->get(ServerAttributes::SERVER_ADDRESS));
        $this->assertSame('GET', $span->getAttributes()->get(HttpAttributes::HTTP_REQUEST_METHOD));
        $this->assertSame('/foo', $span->getAttributes()->get(UrlAttributes::URL_PATH));
        $this->assertSame(200, $span->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
    }

    /**
     * @dataProvider methodProvider
     */
    public function test_helper_methods(string $method, string $expected): void
    {
        $this->mock->append(new Response());
        $this->assertCount(0, $this->storage);
        $this->client->{$method}('/foo');

        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        assert($span instanceof ImmutableSpan);
        $this->assertSame('example.com', $span->getAttributes()->get(ServerAttributes::SERVER_ADDRESS));
        $this->assertSame($expected, $span->getAttributes()->get(HttpAttributes::HTTP_REQUEST_METHOD));
        $this->assertSame('/foo', $span->getAttributes()->get(UrlAttributes::URL_PATH));
        $this->assertSame(200, $span->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
    }

    /**
     * @dataProvider methodProvider
     */
    public function test_helper_methods_async(string $method, string $expected): void
    {
        $this->mock->append(new Response());
        $promise = $this->client->{$method . 'Async'}('/');
        $this->assertCount(0, $this->storage);
        assert($promise instanceof PromiseInterface);
        $promise->wait();

        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        assert($span instanceof ImmutableSpan);
        $this->assertSame($expected, $span->getAttributes()->get(HttpAttributes::HTTP_REQUEST_METHOD));
        $this->assertSame('example.com', $span->getAttributes()->get(ServerAttributes::SERVER_ADDRESS));
        $this->assertSame(200, $span->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
    }

    /**
     * Guzzle 8 removed Client::__call(), so only the methods declared on
     * ClientTrait are covered here. OPTIONS is covered by test_request_methods().
     */
    public static function methodProvider(): array
    {
        return [
            'delete' => ['delete', 'DELETE'],
            'get' => ['get', 'GET'],
            'head' => ['head', 'HEAD'],
            'patch' => ['patch', 'PATCH'],
            'post' => ['post', 'POST'],
            'put' => ['put', 'PUT'],
        ];
    }

    public function test_request_methods(): void
    {
        $this->mock->append(new Response());
        $this->client->request('OPTIONS', '/foo');
        $this->mock->append(new Response());
        $this->client->requestAsync('OPTIONS', '/foo')->wait();

        $this->assertCount(2, $this->storage);
        foreach ($this->storage as $span) {
            assert($span instanceof ImmutableSpan);
            $this->assertSame('OPTIONS', $span->getAttributes()->get(HttpAttributes::HTTP_REQUEST_METHOD));
            $this->assertSame('/foo', $span->getAttributes()->get(UrlAttributes::URL_PATH));
            $this->assertSame(200, $span->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
        }
    }

    public function test_concurrent_async(): void
    {
        $this->mock->append(new Response());
        $this->mock->append(new Response(500));
        $promises = [
            'one' => $this->client->getAsync('www.example.com/one'),
            'two'   => $this->client->getAsync('www.example.com/two'),
        ];
        $this->assertCount(0, $this->storage);
        $_responses = Utils::unwrap($promises);
        $this->assertCount(2, $this->storage);

        $spanOne = $this->storage->offsetGet(0);
        assert($spanOne instanceof ImmutableSpan);
        $spanTwo = $this->storage->offsetGet(1);
        assert($spanTwo instanceof ImmutableSpan);

        $this->assertSame(200, $spanOne->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertSame(500, $spanTwo->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
    }

    public function test_headers_propagation(): void
    {
        $this->handlerStack->push(function (callable $handler) {
            return function (RequestInterface $request, array $options) use ($handler) {
                $this->assertNotNull($request->getHeader('traceparent'));

                return $handler($request, $options);
            };
        });

        $this->mock->append(new Response());
        $this->client->get('/');
    }

    /**
     * @dataProvider promiseProvider
     * @link https://github.com/open-telemetry/opentelemetry-php/issues/1623
     */
    public function test_transfer_promises_execute_for_sync_requests(PromiseInterface $promise, bool $expectedException): void
    {
        /**
         * Approximate the Curl handler's behaviour of returning a FulfilledPromise without any
         * additional promise chaining, so that post hook receives a FulfilledPromise.
         */
        $handler = function () use ($promise): PromiseInterface {
            return $promise;
        };
        $handlerStack = HandlerStack::create($handler);

        $client = new Client([
            'handler' => $handlerStack,
            'base_uri' => 'https://example.com/',
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
        $this->assertCount(0, $this->storage);

        $request = new Request('GET', 'https://example.com/foo');

        try {
            $client->send($request);
        } catch (\Throwable $e) {
            if (!$expectedException) {
                throw $e;
            }
        }

        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        assert($span instanceof ImmutableSpan);
        if ($expectedException) {
            $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
            $this->assertSame('Test exception', $span->getStatus()->getDescription());
        } else {
            $this->assertSame(201, $span->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
        }
    }

    public static function promiseProvider(): array
    {
        return [
            'fulfilled' => [new FulfilledPromise(new Response(201)), false],
            'rejected' => [new RejectedPromise(new \RuntimeException('Test exception')), true],
        ];
    }

    /**
     * @dataProvider exceptionProvider
     */
    public function test_exceptions_enabled_sets_response_attributes($response, ?int $expected = null): void
    {
        $client = new Client([
            'handler' => $this->handlerStack,
            'base_uri' => 'https://example.com/',
            'http_errors' => true,
            'exceptions' => true,
        ]);
        $this->mock->append($response);
        $this->assertCount(0, $this->storage);

        try {
            $client->send(new Request('GET', 'https://example.com/error'));
        } catch (\Exception $e) {
            // Expected exception
        }
        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        $attributes = $span->getAttributes()->toArray();
        if ($expected) {
            $this->assertSame($expected, $attributes[HttpAttributes::HTTP_RESPONSE_STATUS_CODE]);
            $this->assertGreaterThan(0, $attributes[HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE]);
            $this->assertArrayHasKey(NetworkAttributes::NETWORK_PROTOCOL_VERSION, $attributes);
        } else {
            $this->assertArrayNotHasKey(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $attributes);
        }
    }

    /**
     * @link https://github.com/open-telemetry/opentelemetry-php/issues/1988
     */
    public function test_post_hook_when_transfer_throws_synchronously(): void
    {
        $this->assertCount(0, $this->storage);

        // A numerically-indexed "headers" option makes Client::applyOptions()
        // throw InvalidArgumentException synchronously inside transfer(), before
        // a promise is returned. send()/sendAsync() must be used here: the get()
        // magic method routes through requestAsync(), which unsets the headers
        // option before transfer() is called, so applyOptions() never sees it.
        $request = new Request('GET', 'https://example.com/foo');

        try {
            $this->client->send($request, ['headers' => ['invalid']]);
            $this->fail('Expected InvalidArgumentException was not thrown');
        } catch (\InvalidArgumentException $e) {
            // expected
        }

        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        assert($span instanceof ImmutableSpan);
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
    }

    public static function exceptionProvider(): array
    {
        return [
            '400 Bad Request' => [new Response(400, [], 'Bad Request'), 400],
            '404 Not Found' => [new Response(404, [], 'Not Found'), 404],
            '500 Internal Server Error' => [new Response(500, [], 'Internal Server Error'), 500],
            '503 Service Unavailable' => [new Response(503, [], 'Service Unavailable'), 503],
            'network connection error' => [new ConnectException('network error', new Request('GET', 'https://example.com/error'))],
            'runtime exception' => [new \RuntimeException('runtime error')],
        ];
    }

    /**
     * @dataProvider requestHeadersEnvProvider
     */
    public function test_capture_request_headers(string $envVar): void
    {
        putenv(sprintf('%s=x-custom-header,accept', $envVar));

        try {
            $this->mock->append(new Response());
            $request = new Request('GET', 'https://example.com/foo', ['x-custom-header' => 'my-value', 'accept' => 'application/json']);
            $this->client->send($request);

            $span = $this->storage->offsetGet(0);
            assert($span instanceof ImmutableSpan);
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

    /**
     * @dataProvider responseHeadersEnvProvider
     */
    public function test_capture_response_headers(string $envVar): void
    {
        putenv(sprintf('%s=x-custom-header,content-type', $envVar));

        try {
            $this->mock->append(new Response(200, ['x-custom-header' => 'my-value', 'content-type' => 'application/json']));
            $this->client->get('/foo');

            $span = $this->storage->offsetGet(0);
            assert($span instanceof ImmutableSpan);
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
