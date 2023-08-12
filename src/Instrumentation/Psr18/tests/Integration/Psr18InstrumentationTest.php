<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Psr18\tests\Integration;

use ArrayObject;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\TraceAttributes;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

class Psr18InstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    private ArrayObject $storage;
    private TracerProvider $tracerProvider;
    /** @var ClientInterface&\PHPUnit\Framework\MockObject\MockObject */
    private ClientInterface $client;

    public function setUp(): void
    {
        $this->storage = new ArrayObject();
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage)
            )
        );
        $this->client = $this->createMock(ClientInterface::class);

        $this->scope = Configurator::create()
            ->withTracerProvider($this->tracerProvider)
            ->withPropagator(TraceContextPropagator::getInstance())
            ->activate();
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    /**
     * @dataProvider requestProvider
     */
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

        $this->assertCount(0, $this->storage);
        $this->client
            ->expects($this->once())
            ->method('sendRequest')
            ->with($this->callback(function (RequestInterface $request) {
                $this->assertTrue($request->hasHeader('traceparent'), 'traceparent has been injected into request');
                $this->assertNotNull($request->getHeaderLine('traceparent'));

                return true;
            }))
            ->willReturn($response);
        $this->client->sendRequest($request);
        $this->assertCount(1, $this->storage);

        /** @var ImmutableSpan $span */
        $span = $this->storage[0];

        $this->assertStringContainsString($method, $span->getName());
        $this->assertTrue($span->getAttributes()->has(TraceAttributes::HTTP_URL));
        $this->assertSame($uri, $span->getAttributes()->get(TraceAttributes::HTTP_URL));
        $this->assertTrue($span->getAttributes()->has(TraceAttributes::HTTP_METHOD));
        $this->assertSame($method, $span->getAttributes()->get(TraceAttributes::HTTP_METHOD));
        $this->assertTrue($span->getAttributes()->has(TraceAttributes::HTTP_STATUS_CODE));
        $this->assertSame($statusCode, $span->getAttributes()->get(TraceAttributes::HTTP_STATUS_CODE));
    }

    public function requestProvider(): array
    {
        return [
            ['GET', 'http://example.com/foo', 200],
            ['POST', 'https://example.com/bar', 401],
        ];
    }
}
