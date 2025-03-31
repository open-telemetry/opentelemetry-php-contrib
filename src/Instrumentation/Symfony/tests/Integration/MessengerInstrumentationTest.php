<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Contrib\Instrumentation\Symfony\MessengerInstrumentation;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\InMemoryTransport as LegacyInMemoryTransport;
use Symfony\Component\Messenger\Transport\TransportInterface;
use ArrayObject;

final class SendEmailMessage
{
    private string $message;

    public function __construct(string $message)
    {
        $this->message = $message;
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function getMessage(): string
    {
        return $this->message;
    }
}

final class SendEmailMessageHandler
{
    public function __invoke(SendEmailMessage $message)
    {
        // Handler logic
        return 'handled';
    }
}

final class MessengerInstrumentationTest extends AbstractTest
{
    protected function getMessenger(): MessageBusInterface
    {
        return new MessageBus();
    }
    protected function getTransport()
    {
        // Symfony 6+
        if (class_exists(InMemoryTransport::class)) {
            return new InMemoryTransport();
        }

        // Symfony 5+
        return new LegacyInMemoryTransport();
    }

    public function setUp(): void
    {
        MessengerInstrumentation::register();
        parent::setUp();
        $this->storage = new ArrayObject();
    }

    /**
     * @dataProvider dispatchDataProvider
     * @param mixed $message
     * @param string $spanName
     * @param int $kind
     * @param array $attributes
     */
    public function test_dispatch_message($message, string $spanName, int $kind, array $attributes)
    {
        $bus = $this->getMessenger();

        $bus->dispatch($message);

        $this->assertCount(1, $this->storage);

        $span = $this->storage[0];

        $this->assertEquals($spanName, $span->getName());
        $this->assertEquals($kind, $span->getKind());

        foreach ($attributes as $key => $value) {
            $this->assertTrue($span->getAttributes()->has($key), sprintf('Attribute %s not found', $key));
            $this->assertEquals($value, $span->getAttributes()->get($key));
        }
    }

    /**
     * @dataProvider sendDataProvider
     * @param mixed $message
     * @param string $spanName
     * @param int $kind
     * @param array $attributes
     */
    public function test_send_message($message, string $spanName, int $kind, array $attributes)
    {
        $transport = $this->getTransport();
        $transport->send(new Envelope($message));

        $this->assertCount(1, $this->storage);

        $span = $this->storage[0];

        $this->assertEquals($spanName, $span->getName());
        $this->assertEquals($kind, $span->getKind());

        foreach ($attributes as $key => $value) {
            $this->assertTrue($span->getAttributes()->has($key), sprintf('Attribute %s not found', $key));
            $this->assertEquals($value, $span->getAttributes()->get($key));
        }
    }

    public function test_can_sustain_throw_while_dispatching()
    {
        $bus = new class() implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \Exception('booo!');
            }
        };

        try {
            $bus->dispatch(new SendEmailMessage('Hello Again'));
        } catch (\Throwable $e) {
            $this->assertCount(1, $this->storage);
            $this->assertArrayHasKey(0, $this->storage);
        }
    }

    public function test_can_sustain_throw_while_sending()
    {
        $transport = new class() implements TransportInterface {
            public function get(): iterable
            {
                throw new \Exception('booo!');
            }

            public function ack(Envelope $envelope): void
            {
                throw new \Exception('booo!');
            }

            public function reject(Envelope $envelope): void
            {
                throw new \Exception('booo!');
            }

            public function send(Envelope $envelope): Envelope
            {
                throw new \Exception('booo!');
            }
        };

        try {
            $transport->send(new Envelope(new SendEmailMessage('Hello Again')));
        } catch (\Throwable $e) {
            $this->assertCount(1, $this->storage);
            $this->assertArrayHasKey(0, $this->storage);
        }
    }

    public function test_handle_message()
    {
        $bus = $this->getMessenger();
        $transport = $this->getTransport();
        $worker = new \Symfony\Component\Messenger\Worker(
            ['transport' => $transport],
            $bus
        );

        // Send a message to the transport
        $message = new SendEmailMessage('Hello Again');
        $envelope = new Envelope($message);
        $transport->send($envelope);

        // Get and handle the message
        $messages = iterator_to_array($transport->get());
        $message = $messages[0];
        
        // Use reflection to call the protected handleMessage method
        $reflection = new \ReflectionClass($worker);
        $handleMessageMethod = $reflection->getMethod('handleMessage');
        $handleMessageMethod->setAccessible(true);
        $handleMessageMethod->invoke($worker, $message, 'transport');

        // We should have 3 spans: send, dispatch, and consume
        $this->assertCount(3, $this->storage);

        // Check the send span
        $sendSpan = $this->storage[0];
        $this->assertEquals(
            'SEND OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
            $sendSpan->getName()
        );
        $this->assertEquals(SpanKind::KIND_PRODUCER, $sendSpan->getKind());

        // Check the dispatch span
        $dispatchSpan = $this->storage[1];
        $this->assertEquals(
            'DISPATCH Symfony\Component\Messenger\Envelope',
            $dispatchSpan->getName()
        );
        $this->assertEquals(SpanKind::KIND_PRODUCER, $dispatchSpan->getKind());

        // Check the consume span
        $consumeSpan = $this->storage[2];
        $this->assertEquals(
            'CONSUME OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
            $consumeSpan->getName()
        );
        $this->assertEquals(SpanKind::KIND_CONSUMER, $consumeSpan->getKind());
    }

    public function test_middleware_instrumentation()
    {
        if (!getenv('OTEL_PHP_MESSENGER_INSTRUMENT_MIDDLEWARES')) {
            $this->markTestSkipped('Middleware instrumentation is not enabled');
        }

        $bus = $this->getMessenger();
        $message = new SendEmailMessage('Hello Again');
        $envelope = new Envelope($message);

        // Create a test middleware
        $middleware = new class() implements \Symfony\Component\Messenger\Middleware\MiddlewareInterface {
            public function handle(Envelope $envelope, \Symfony\Component\Messenger\Middleware\StackInterface $stack): Envelope
            {
                return $stack->next()->handle($envelope, $stack);
            }
        };

        // Handle the message through the middleware
        $middleware->handle($envelope, new class() implements \Symfony\Component\Messenger\Middleware\StackInterface {
            public function next(): \Symfony\Component\Messenger\Middleware\StackInterface
            {
                return $this;
            }
        });

        // We should have a middleware span
        $this->assertCount(1, $this->storage);
        $middlewareSpan = $this->storage[0];
        $this->assertEquals(
            'MIDDLEWARE class@anonymous::OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
            $middlewareSpan->getName()
        );
        $this->assertEquals(SpanKind::KIND_INTERNAL, $middlewareSpan->getKind());
        $this->assertTrue($middlewareSpan->getAttributes()->has('messaging.symfony.middleware'));
    }

    public function test_stamp_information()
    {
        $transport = $this->getTransport();
        $message = new SendEmailMessage('Hello Again');
        
        // Add various stamps to the envelope
        $envelope = new Envelope($message, [
            new \Symfony\Component\Messenger\Stamp\BusNameStamp('test_bus'),
            new \Symfony\Component\Messenger\Stamp\DelayStamp(1000),
            new \Symfony\Component\Messenger\Stamp\TransportMessageIdStamp('test-id'),
        ]);

        $transport->send($envelope);

        // We should have a send span with all stamp information
        $this->assertCount(1, $this->storage);
        $sendSpan = $this->storage[0];
        
        // Check stamp attributes
        $this->assertTrue($sendSpan->getAttributes()->has('messaging.symfony.bus'));
        $this->assertEquals('test_bus', $sendSpan->getAttributes()->get('messaging.symfony.bus'));
        
        $this->assertTrue($sendSpan->getAttributes()->has('messaging.symfony.delay'));
        $this->assertEquals(1000, $sendSpan->getAttributes()->get('messaging.symfony.delay'));
        
        $this->assertTrue($sendSpan->getAttributes()->has('messaging.message_id'));
        $this->assertEquals('test-id', $sendSpan->getAttributes()->get('messaging.message_id'));
        
        // Check stamps count
        $this->assertTrue($sendSpan->getAttributes()->has('messaging.symfony.stamps'));
        $stamps = json_decode($sendSpan->getAttributes()->get('messaging.symfony.stamps'), true);
        $this->assertIsArray($stamps);
        $this->assertArrayHasKey('Symfony\Component\Messenger\Stamp\BusNameStamp', $stamps);
        $this->assertArrayHasKey('Symfony\Component\Messenger\Stamp\DelayStamp', $stamps);
        $this->assertArrayHasKey('Symfony\Component\Messenger\Stamp\TransportMessageIdStamp', $stamps);
    }

    public function test_throw_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('test');

        $bus = $this->getBus(__FUNCTION__);
        $bus->dispatch(new TestMessage());
    }

    public function test_throw_exception_with_retry(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('test');

        $bus = $this->getBus(__FUNCTION__);
        $bus->dispatch(new TestMessageWithRetry());
    }

    public function sendDataProvider(): array
    {
        return [
            [
                new SendEmailMessage('Hello Again'),
                'SEND OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
                SpanKind::KIND_PRODUCER,
                [
                    MessengerInstrumentation::ATTRIBUTE_MESSENGER_TRANSPORT => class_exists('Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport') ? 'Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport' : 'Symfony\Component\Messenger\Transport\InMemoryTransport',
                    MessengerInstrumentation::ATTRIBUTE_MESSENGER_MESSAGE => 'OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
                ],
            ],
        ];
    }

    public function dispatchDataProvider(): array
    {
        return [
            [
                new SendEmailMessage('Hello Again'),
                'DISPATCH OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
                SpanKind::KIND_PRODUCER,
                [
                    MessengerInstrumentation::ATTRIBUTE_MESSENGER_BUS => 'Symfony\Component\Messenger\MessageBus',
                    MessengerInstrumentation::ATTRIBUTE_MESSENGER_MESSAGE => 'OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
                ],
            ],
        ];
    }
}
