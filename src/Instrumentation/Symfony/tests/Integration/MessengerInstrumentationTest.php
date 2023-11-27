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
use Symfony\Component\Messenger\Transport\InMemoryTransport as LegacyInmemoryTransport;

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
        // Symfony 6+
        if (class_exists(InMemoryTransport::class)) {
            return new InMemoryTransport();
        }

        // Symfony 5+
        return new LegacyInmemoryTransport();
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

    public function sendDataProvider(): array
    {
        return [
            [
                new SendEmailMessage('Hello Again'),
                'SEND OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
                SpanKind::KIND_INTERNAL,
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
                SpanKind::KIND_INTERNAL,
                [
                    MessengerInstrumentation::ATTRIBUTE_MESSENGER_BUS => 'Symfony\Component\Messenger\MessageBus',
                    MessengerInstrumentation::ATTRIBUTE_MESSENGER_MESSAGE => 'OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
                ],
            ],
        ];
    }
}
