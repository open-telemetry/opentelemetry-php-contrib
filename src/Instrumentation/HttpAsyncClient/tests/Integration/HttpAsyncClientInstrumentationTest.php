<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\HttpAsyncClient\tests\Integration;

use ArrayObject;
use Http\Client\HttpAsyncClient;
use Http\Promise\FulfilledPromise;
use Http\Promise\Promise;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * @covers \OpenTelemetry\Contrib\Instrumentation\HttpAsyncClient\HttpAsyncClientInstrumentation
 * @covers \OpenTelemetry\Contrib\Instrumentation\HttpAsyncClient\HeadersPropagator
 */
class HttpAsyncClientInstrumentationTest extends TestCase
{
    // @var HttpAsyncClient&MockObject $client
    private $client;
    private ScopeInterface $scope;
    private ArrayObject $storage;

    public function setUp(): void
    {
        $this->client = $this->createMock(HttpAsyncClient::class);

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

    public function test_send_async_requests(): void
    {
        $request = new Request('GET', 'http://example.com');
        $p1 = $this->promise(new Response(200));
        $p2 = $this->promise(new \Exception());
        $this->client->method('sendAsyncRequest')->willReturnOnConsecutiveCalls(
            $p1,
            $p2,
        );
        $this->assertCount(0, $this->storage);
        $this->client->sendAsyncRequest($request);
        $this->client->sendAsyncRequest($request);
        $this->assertCount(0, $this->storage, 'no spans exported since promises are not resolved yet');

        //resolve promises
        try {
            $p2->wait();
        } catch (\Exception $e) {
            //expected exception
        }
        $this->assertCount(1, $this->storage);
        $p1->wait();
        $this->assertCount(2, $this->storage);
    }

    /**
     * @param ResponseInterface|Throwable $response
     */
    private function promise($response): Promise
    {
        return new class($response) implements Promise {
            private $response;
            private $onFulfilled = null;
            private $onRejected = null;

            public function __construct($response)
            {
                $this->response = $response;
            }

            public function then(callable $onFulfilled = null, callable $onRejected = null): Promise
            {
                $this->onFulfilled = $onFulfilled;
                $this->onRejected = $onRejected;

                return $this;
            }

            public function getState(): string
            {
                return 'unused';
            }

            public function wait($unwrap = true)
            {
                $c = ($this->response instanceof \Throwable) ? $this->onRejected : $this->onFulfilled;
                if (is_callable($c)) {
                    return $c($this->response);
                }

                return new FulfilledPromise($this->response);
            }
        };
    }
}
