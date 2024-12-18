<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Contrib\Instrumentation\Symfony\MessengerInstrumentation;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\InMemoryTransport as LegacyInMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

final class SendEmailMessage
{
    private string $message;

    public function __construct(string $message)
    {
        $this->message = $message;
    }

    public function getMessage(): string
    {
        return $this->message;
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
        // Symfony 6+ version of the transport
        if (class_exists(InMemoryTransport::class)) {
            return new InMemoryTransport();
        }

        // Symfony 5+ fallback
        return new LegacyInMemoryTransport();
    }

    protected function getReceiver()
    {
        // Symfony 6+ version of the receiver
        if (class_exists(ReceiverInterface::class)) {
            return new InMemoryTransport(); // Example transport acting as a receiver
        }

        // Symfony 5+ fallback
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
        $bus->dispatch($message); // Target the correct interface (MessageBusInterface)

        $this->assertCount(1, $this->storage);

        /** @var ImmutableSpan $span */
        $span = $this->storage[0];

        $this->assertEquals($spanName, $span->getName());
        $this->assertEquals($kind, $span->getKind());

        foreach ($attributes as $key => $value) {
            $this->assertTrue($span->getAttributes()->has($key), sprintf('Attribute %s not found', $key));
            $this->assertEquals($value, $span->getAttributes()->get($key));
        }
    }

    /**
     * Test consumer span when processing a message
     */
    public function test_consume_message()
    {
        $transport = $this->getReceiver(); // Use the correct receiver interface
        $message = new SendEmailMessage('Hello Consumer');
        $envelope = new Envelope($message);

        // Simulate receiving the message via ReceiverInterface::get
        $transport->get();

        // Simulate message consumption (processing)
        $bus = $this->getMessenger();
        $bus->dispatch($message);

        // After message is consumed, we expect a consumer span
        $this->assertCount(1, $this->storage);

        /** @var ImmutableSpan $span */
        $span = $this->storage[0];

        // We expect this to be a consumer span
        $this->assertEquals('CONSUME OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage', $span->getName());
        $this->assertEquals(SpanKind::KIND_CONSUMER, $span->getKind());

        $this->assertTrue($span->getAttributes()->has(MessengerInstrumentation::ATTRIBUTE_MESSENGER_MESSAGE));
        $this->assertEquals('OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage', $span->getAttributes()->get(MessengerInstrumentation::ATTRIBUTE_MESSENGER_MESSAGE));
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

        /** @var ImmutableSpan $span */
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

            /** @var ImmutableSpan $span */
            $span = $this->storage[0];
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

            $span = $this->storage[0];
        }
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
                SpanKind::KIND_PROCESS, // Correct SpanKind for dispatching
                [
                    MessengerInstrumentation::ATTRIBUTE_MESSENGER_BUS => 'Symfony\Component\Messenger\MessageBus',
                    MessengerInstrumentation::ATTRIBUTE_MESSENGER_MESSAGE => 'OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
                ],
            ],
        ];
    }
}
