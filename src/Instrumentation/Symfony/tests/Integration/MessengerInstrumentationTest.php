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

        // Check the consumer span
        $consumeSpan = $this->storage[2];
        $this->assertEquals(
            'CONSUME OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
            $consumeSpan->getName()
        );
        $this->assertEquals(SpanKind::KIND_CONSUMER, $consumeSpan->getKind());
        $this->assertTrue($consumeSpan->getAttributes()->has(MessengerInstrumentation::ATTRIBUTE_MESSAGING_SYSTEM));
        $this->assertEquals('symfony', $consumeSpan->getAttributes()->get(MessengerInstrumentation::ATTRIBUTE_MESSAGING_SYSTEM));
        $this->assertEquals('receive', $consumeSpan->getAttributes()->get(MessengerInstrumentation::ATTRIBUTE_MESSAGING_OPERATION));
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
