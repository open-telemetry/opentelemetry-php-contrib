<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration;

use OpenTelemetry\SDK\Trace\ImmutableSpan;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\InMemoryTransport as LegacyInmemoryTransport;

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
     * @dataProvider messageDataProvider
     * @param mixed $message
     * @param string $spanName
     */
    public function test_dispatch_message($message, string $spanName)
    {
        $bus = $this->getMessenger();

        $bus->dispatch($message);

        $this->assertCount(1, $this->storage);

        /** @var ImmutableSpan $span */
        $span = $this->storage[0];

        $this->assertEquals($spanName, $span->getName());
        $this->assertEquals('foo', $span->getName());
    }

    /**
     * @dataProvider messageDataProvider
     * @param mixed $message
     * @param string $spanName
     */
    public function test_send_message($message, string $spanName)
    {
        $transport = $this->getTransport();
        $transport->send(new Envelope($message));

        $this->assertCount(1, $this->storage);

        /** @var ImmutableSpan $span */
        $span = $this->storage[0];

        $this->assertEquals($spanName, $span->getName());
        $this->assertEquals('foo', $span->getName());
    }

    protected function messageDataProvider(): array
    {
        return [
            ['foo', 'foo'],
            ['bar', 'bar'],
        ];
    }
}
