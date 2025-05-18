<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\ReactPHP\tests\Integration;

use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Contrib\Instrumentation\ReactPHP\ReactPHPInstrumentation;
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

class ReactPHPInstrumentationTest extends TestCase
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
                /** @psalm-suppress InternalMethod */
                return match ($request->getUri()->getPath()) {
                    '/network_error' => resolve(
                        (new Response())
                            ->withStatus(400)
                            ->withAddedHeader('Content-Type', 'text/plain; charset=utf-8')
                    ),
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

        $this->browser->request('GET', 'http://example.com/success?query#fragment')->then();

        $this->assertCount(1, $this->storage);

        $span = $this->storage->offsetGet(0);
        assert($span instanceof ImmutableSpan);
        $this->assertSame('GET', $span->getName());
        $this->assertSame('GET', $span->getAttributes()->get(TraceAttributes::HTTP_REQUEST_METHOD));
        $this->assertSame('example.com', $span->getAttributes()->get(TraceAttributes::SERVER_ADDRESS));
        $this->assertSame('http://example.com/success?query#fragment', $span->getAttributes()->get(TraceAttributes::URL_FULL));
        $this->assertSame('React\Http\Io\Transaction::send', $span->getAttributes()->get(TraceAttributes::CODE_FUNCTION_NAME));
        $this->assertSame(80, $span->getAttributes()->get(TraceAttributes::SERVER_PORT));
        $this->assertNotEmpty($span->getAttributes()->get(sprintf('%s.%s', TraceAttributes::HTTP_REQUEST_HEADER, 'traceparent')));
        $this->assertStringEndsWith('vendor/react/http/src/Io/Transaction.php', $span->getAttributes()->get(TraceAttributes::CODE_FILE_PATH));
        $this->assertSame(200, $span->getAttributes()->get(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertSame('1.1', $span->getAttributes()->get(TraceAttributes::NETWORK_PROTOCOL_VERSION));
        $this->assertSame(['text/plain; charset=utf-8'], $span->getAttributes()->get(sprintf('%s.%s', TraceAttributes::HTTP_RESPONSE_HEADER, 'content-type')));
    }

    public function test_fulfilled_promise_with_redactions(): void
    {
        $this->browser->request('GET', 'http://username@example.com/success')->then();

        $span = $this->storage->offsetGet(0);
        $this->assertSame('http://REDACTED@example.com/success', $span->getAttributes()->get(TraceAttributes::URL_FULL));

        $this->browser->request('GET', 'http://username:password@example.com/success?Signature=private')->then();

        $span = $this->storage->offsetGet(1);
        $this->assertSame('http://REDACTED:REDACTED@example.com/success?Signature=REDACTED', $span->getAttributes()->get(TraceAttributes::URL_FULL));
    }

    public function test_fulfilled_promise_with_overridden_methods(): void
    {
        $this->browser->request('CUSTOM', 'http://example.com:8888/success')->then();

        $span = $this->storage->offsetGet(0);
        $this->assertSame('CUSTOM', $span->getName());
        $this->assertSame('CUSTOM', $span->getAttributes()->get(TraceAttributes::HTTP_REQUEST_METHOD));
        $this->assertSame(8888, $span->getAttributes()->get(TraceAttributes::SERVER_PORT));
        $this->assertNull($span->getAttributes()->get(TraceAttributes::HTTP_REQUEST_METHOD_ORIGINAL));
    }

    public function test_fulfilled_promise_with_unknown_method(): void
    {
        $this->browser->request('UNKNOWN', 'http://example.com/success')->then();

        $span = $this->storage->offsetGet(0);
        $this->assertSame('HTTP', $span->getName());
        $this->assertSame('_OTHER', $span->getAttributes()->get(TraceAttributes::HTTP_REQUEST_METHOD));
        $this->assertSame('UNKNOWN', $span->getAttributes()->get(TraceAttributes::HTTP_REQUEST_METHOD_ORIGINAL));
    }

    public function test_fulfilled_promise_with_error(): void
    {
        $browser = $this->browser->withRejectErrorResponse(false);
        $browser->request('GET', 'http://example.com/network_error')->then();

        $span = $this->storage->offsetGet(0);
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('400', $span->getAttributes()->get(TraceAttributes::ERROR_TYPE));
        $this->assertSame(400, $span->getAttributes()->get(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));
    }

    public function test_rejected_promise_with_response_exception(): void
    {
        $this->browser->request('GET', 'http://example.com/network_error')->then(null, function () {});

        $span = $this->storage->offsetGet(0);
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('400', $span->getAttributes()->get(TraceAttributes::ERROR_TYPE));
        $this->assertSame(400, $span->getAttributes()->get(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));
        $event = $span->getEvents()[0];
        $this->assertSame('exception', $event->getName());
        $this->assertSame(ResponseException::class, $event->getAttributes()->get(TraceAttributes::EXCEPTION_TYPE));
        $this->assertSame('HTTP status code 400 (Bad Request)', $event->getAttributes()->get(TraceAttributes::EXCEPTION_MESSAGE));
        $this->assertSame(['text/plain; charset=utf-8'], $span->getAttributes()->get(sprintf('%s.%s', TraceAttributes::HTTP_RESPONSE_HEADER, 'content-type')));
    }

    public function test_rejected_promise_with_unknown_exception(): void
    {
        $this->browser->request('GET', 'http://example.com/unknown_error')->then(null, function () {});

        $span = $this->storage->offsetGet(0);
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('Exception', $span->getAttributes()->get(TraceAttributes::ERROR_TYPE));
        $event = $span->getEvents()[0];
        $this->assertSame('exception', $event->getName());
        $this->assertSame('Exception', $event->getAttributes()->get(TraceAttributes::EXCEPTION_TYPE));
        $this->assertSame('Unknown', $event->getAttributes()->get(TraceAttributes::EXCEPTION_MESSAGE));
    }

    public function test_can_register(): void
    {
        $this->expectNotToPerformAssertions();

        ReactPHPInstrumentation::register();
    }

    public function test_bail_on_noop(): void
    {
        $scope = Configurator::createNoop()->activate();
        $this->browser->request('GET', 'http://example.com/success')->then();
        $scope->detach();

        $this->assertCount(0, $this->storage);
    }
}
