<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\ReactHttp\tests\Integration;

use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\TraceAttributes;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Http\Io\Sender;
use React\Http\Io\Transaction;
use React\Http\Message\Response;
use React\Http\Message\ResponseException;
use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * @covers \OpenTelemetry\Contrib\Instrumentation\ReactHttp\ReactHttpInstrumentation
 */
class ReactHttpInstrumentationTest extends TestCase
{
    private Browser $browser;

    private ArrayObject $storage;
    private TracerProvider $tracerProvider;
    private ScopeInterface $scope;

    public function setUp(): void
    {
        /**
         * Browser/Transaction set up, pulled from ReactPHP tests:
         * @see https://github.com/reactphp/http/blob/1.x/tests/BrowserTest.php
         * @see https://github.com/reactphp/http/blob/1.x/tests/Io/TransactionTest.php
         *
         * @var LoopInterface&MockObject
         */
        $loop = $this->createMock(LoopInterface::class);
        /** @var Sender&MockObject */
        $sender = $this->createMock(Sender::class);
        $sender->method('send')
            ->willReturnCallback(function (RequestInterface $request) {
                return match ($request->getUri()->getPath()) {
                    '/network_error' => resolve((new Response())->withStatus(400)),
                    '/unknown_error' => reject(new \Exception('Unknown')),
                    default => resolve(Response::plaintext('Hello world'))
                };
            });
        /** @psalm-suppress InternalClass,InternalMethod */
        $transaction = new Transaction($sender, $loop);
        $this->browser = new Browser(null, $loop);
        $ref = new \ReflectionProperty($this->browser, 'transaction');
        $ref->setValue($this->browser, $transaction);

        /**
         * OpenTelemetry set up
         */
        $this->storage = new ArrayObject();
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage)
            )
        );

        $this->scope = Configurator::create()
            ->withTracerProvider($this->tracerProvider)
            ->withPropagator(TraceContextPropagator::getInstance())
            ->activate();
    }

    public function tearDown(): void
    {
        $this->scope->detach();
        $this->tracerProvider->shutdown();
    }

    public function test_fulfilled_promise(): void
    {
        $this->assertCount(0, $this->storage);

        $promise = $this->browser->request('GET', 'http://example.com/success');
        $promise->then();

        $this->assertCount(1, $this->storage);

        $span = $this->storage->offsetGet(0);
        assert($span instanceof ImmutableSpan);
        $this->assertSame('GET', $span->getAttributes()->get(TraceAttributes::HTTP_REQUEST_METHOD));
        $this->assertSame('example.com', $span->getAttributes()->get(TraceAttributes::SERVER_ADDRESS));
        $this->assertNull($span->getAttributes()->get(TraceAttributes::SERVER_PORT));
        $this->assertSame('http://example.com/success', $span->getAttributes()->get(TraceAttributes::URL_FULL));
        $this->assertSame('http', $span->getAttributes()->get(TraceAttributes::NETWORK_PROTOCOL_NAME));
        $this->assertSame('1.1', $span->getAttributes()->get(TraceAttributes::NETWORK_PROTOCOL_VERSION));
        $this->assertSame('', $span->getAttributes()->get(TraceAttributes::HTTP_REQUEST_BODY_SIZE));
        $this->assertSame('http', $span->getAttributes()->get(TraceAttributes::URL_SCHEME));
        $this->assertSame('ReactPHP/1', $span->getAttributes()->get(TraceAttributes::USER_AGENT_ORIGINAL));
        $this->assertSame('React\Http\Io\Transaction::send', $span->getAttributes()->get(TraceAttributes::CODE_FUNCTION_NAME));
        $this->assertStringEndsWith('vendor/react/http/src/Io/Transaction.php', $span->getAttributes()->get(TraceAttributes::CODE_FILE_PATH));
        $this->assertSame(200, $span->getAttributes()->get(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertSame('', $span->getAttributes()->get(TraceAttributes::HTTP_RESPONSE_BODY_SIZE));
    }

    /**
     * Requires adding the following two lines to php.ini
     * otel.instrumentation.http.request_headers[]="Accept"
     * otel.instrumentation.http.response_headers[]="Content-Type"
     */
    /*public function test_tracked_headers(): void
    {
        $this->assertCount(0, $this->storage);

        $promise = $this->browser->request('GET', 'http://example.com/success', ['Accept' => 'text/plain']);
        $promise->then();

        $this->assertCount(1, $this->storage);

        $span = $this->storage->offsetGet(0);
        assert($span instanceof ImmutableSpan);
        $this->assertSame('text/plain', $span->getAttributes()->get(sprintf('%s.%s', TraceAttributes::HTTP_REQUEST_HEADER, 'accept')));
        $this->assertSame('text/plain; charset=utf-8', $span->getAttributes()->get(sprintf('%s.%s', TraceAttributes::HTTP_RESPONSE_HEADER, 'content-type')));
    }*/

    public function test_rejected_promise_with_response_exception(): void
    {
        $this->assertCount(0, $this->storage);

        $promise = $this->browser->request('GET', 'http://example.com/network_error');
        $promise->then(null, function () {});

        $this->assertCount(1, $this->storage);

        $span = $this->storage->offsetGet(0);
        assert($span instanceof ImmutableSpan);
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('400', $span->getAttributes()->get(TraceAttributes::ERROR_TYPE));
        $this->assertSame(400, $span->getAttributes()->get(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertSame('', $span->getAttributes()->get(TraceAttributes::HTTP_RESPONSE_BODY_SIZE));
        $event = $span->getEvents()[0];
        $this->assertSame('exception', $event->getName());
        $this->assertSame(ResponseException::class, $event->getAttributes()->get(TraceAttributes::EXCEPTION_TYPE));
        $this->assertSame('HTTP status code 400 (Bad Request)', $event->getAttributes()->get(TraceAttributes::EXCEPTION_MESSAGE));
    }

    public function test_rejected_promise_with_unknown_exception(): void
    {
        $this->assertCount(0, $this->storage);

        $promise = $this->browser->request('GET', 'http://example.com/unknown_error');
        $promise->then(null, function () {});

        $this->assertCount(1, $this->storage);

        $span = $this->storage->offsetGet(0);
        assert($span instanceof ImmutableSpan);
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('Exception', $span->getAttributes()->get(TraceAttributes::ERROR_TYPE));
        $event = $span->getEvents()[0];
        $this->assertSame('exception', $event->getName());
        $this->assertSame('Exception', $event->getAttributes()->get(TraceAttributes::EXCEPTION_TYPE));
        $this->assertSame('Unknown', $event->getAttributes()->get(TraceAttributes::EXCEPTION_MESSAGE));
    }
}
