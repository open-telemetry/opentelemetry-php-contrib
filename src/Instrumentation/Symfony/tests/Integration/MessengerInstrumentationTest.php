<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Contrib\Instrumentation\Symfony\MessengerInstrumentation;
use OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\Fixtures\ForwardingSender;
use OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\Fixtures\LazyContainer;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\TraceableMessageBus;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\InMemoryTransport as LegacyInMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;
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

final class MessengerInstrumentationTest extends AbstractTest
{
    protected function getMessenger(): MessageBusInterface
    {
        return new MessageBus();
    }
    /**
     * Its handler dispatches another message of the same class, once.
     */
    protected function getMessengerDispatchingFromItsHandler(): MessageBusInterface
    {
        $bus = null;
        $bus = new MessageBus([
            new HandleMessageMiddleware(new HandlersLocator([
                SendEmailMessage::class => [static function (SendEmailMessage $message) use (&$bus): void {
                    if ('Hello Again' === $message->getMessage() && $bus instanceof MessageBusInterface) {
                        $bus->dispatch(new SendEmailMessage('Hello from the handler'));
                    }
                }],
            ])),
        ]);

        return $bus;
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

    public function test_dispatch_of_received_message_is_a_consumer_span()
    {
        $bus = $this->getMessenger();

        // A worker re-dispatches a message pulled from a transport with a ReceivedStamp.
        $envelope = (new Envelope(new SendEmailMessage('Hello Again')))->with(new ReceivedStamp('async'));
        $bus->dispatch($envelope);

        $this->assertCount(1, $this->storage);
        $span = $this->storage[0];

        $this->assertEquals(
            'CONSUME OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
            $span->getName(),
        );
        $this->assertEquals(SpanKind::KIND_CONSUMER, $span->getKind());
        $this->assertEquals(
            'OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
            $span->getAttributes()->get(MessengerInstrumentation::ATTRIBUTE_MESSENGER_MESSAGE),
        );
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

    public function test_message_routed_by_a_worker_creates_a_single_consumer_span()
    {
        $bus = new MessageBus();
        $routableBus = new RoutableMessageBus(new LazyContainer(['bus' => static fn () => $bus]));

        $routableBus->dispatch(new Envelope(new SendEmailMessage('Hello Again'), [new BusNameStamp('bus'), new ReceivedStamp('async')]));

        $this->assertCount(1, $this->storage);
        $this->assertEquals(
            'CONSUME OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
            $this->storage[0]->getName(),
        );
        $this->assertEquals(RoutableMessageBus::class, $this->storage[0]->getAttributes()->get(MessengerInstrumentation::ATTRIBUTE_MESSENGER_BUS));
    }

    public function test_message_dispatched_while_handling_a_routed_message_is_a_child_of_its_span()
    {
        $bus = $this->getMessengerDispatchingFromItsHandler();
        $routableBus = new RoutableMessageBus(new LazyContainer(['bus' => static fn () => $bus]));

        $routableBus->dispatch(new Envelope(new SendEmailMessage('Hello Again'), [new BusNameStamp('bus'), new ReceivedStamp('async')]));

        $this->assertCount(2, $this->storage);
        $this->assertEquals(
            'DISPATCH OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
            $this->storage[0]->getName(),
        );
        $this->assertEquals(
            'CONSUME OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
            $this->storage[1]->getName(),
        );
        $this->assertSame($this->storage[1]->getSpanId(), $this->storage[0]->getParentSpanId());
    }

    public function test_handler_dispatching_another_message_of_the_same_class_creates_its_own_span()
    {
        $bus = $this->getMessengerDispatchingFromItsHandler();

        $bus->dispatch(new SendEmailMessage('Hello Again'));

        $this->assertCount(2, $this->storage);
        $this->assertEquals(
            'DISPATCH OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
            $this->storage[0]->getName(),
        );
        $this->assertEquals(
            'DISPATCH OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
            $this->storage[1]->getName(),
        );
        $this->assertSame($this->storage[1]->getSpanId(), $this->storage[0]->getParentSpanId());
    }

    public function test_handler_dispatching_its_message_again_creates_its_own_span()
    {
        $bus = null;
        $redispatched = false;
        $bus = new MessageBus([
            new HandleMessageMiddleware(new HandlersLocator([
                SendEmailMessage::class => [static function (SendEmailMessage $message) use (&$bus, &$redispatched): void {
                    if (!$redispatched && $bus instanceof MessageBusInterface) {
                        $redispatched = true;
                        $bus->dispatch($message);
                    }
                }],
            ])),
        ]);

        $bus->dispatch(new SendEmailMessage('Hello Again'));

        $this->assertCount(2, $this->storage);
        $this->assertSame($this->storage[1]->getSpanId(), $this->storage[0]->getParentSpanId());
    }

    public function test_handler_dispatching_its_message_to_another_bus_creates_its_own_span()
    {
        $otherBus = new MessageBus();
        $bus = new TraceableMessageBus(new MessageBus([
            new HandleMessageMiddleware(new HandlersLocator([
                SendEmailMessage::class => [static function (SendEmailMessage $message) use ($otherBus): void {
                    $otherBus->dispatch($message);
                }],
            ])),
        ]));

        $bus->dispatch(new SendEmailMessage('Hello Again'));

        $this->assertCount(2, $this->storage);
        $this->assertEquals(MessageBus::class, $this->storage[0]->getAttributes()->get(MessengerInstrumentation::ATTRIBUTE_MESSENGER_BUS));
        $this->assertEquals(TraceableMessageBus::class, $this->storage[1]->getAttributes()->get(MessengerInstrumentation::ATTRIBUTE_MESSENGER_BUS));
        $this->assertSame($this->storage[1]->getSpanId(), $this->storage[0]->getParentSpanId());
    }

    public function test_failure_of_a_decorated_bus_is_recorded_on_its_single_span()
    {
        $bus = new TraceableMessageBus(new MessageBus([
            new HandleMessageMiddleware(new HandlersLocator([SendEmailMessage::class => [static function (): void {
                throw new \Exception('booo!');
            }]])),
        ]));

        try {
            $bus->dispatch(new SendEmailMessage('Hello Again'));
        } catch (\Exception $e) {
        }

        $this->assertCount(1, $this->storage);
        $this->assertEquals(StatusCode::STATUS_ERROR, $this->storage[0]->getStatus()->getCode());
        $this->assertCount(1, $this->storage[0]->getEvents());
        $this->assertEquals(TraceableMessageBus::class, $this->storage[0]->getAttributes()->get(MessengerInstrumentation::ATTRIBUTE_MESSENGER_BUS));
    }

    public function test_message_sent_through_a_decorated_sender_creates_a_single_producer_span()
    {
        $sender = new ForwardingSender($this->getTransport());

        $sender->send(new Envelope(new SendEmailMessage('Hello Again')));

        $this->assertCount(1, $this->storage);
        $this->assertEquals(
            'SEND OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
            $this->storage[0]->getName(),
        );
        $this->assertEquals(ForwardingSender::class, $this->storage[0]->getAttributes()->get(MessengerInstrumentation::ATTRIBUTE_MESSENGER_TRANSPORT));
    }

    public function test_failure_of_a_decorated_sender_is_recorded_on_its_single_span()
    {
        $sender = new ForwardingSender(new class() implements SenderInterface {
            public function send(Envelope $envelope): Envelope
            {
                throw new \Exception('booo!');
            }
        });

        try {
            $sender->send(new Envelope(new SendEmailMessage('Hello Again')));
        } catch (\Exception $e) {
        }

        $this->assertCount(1, $this->storage);
        $this->assertEquals(StatusCode::STATUS_ERROR, $this->storage[0]->getStatus()->getCode());
        $this->assertEquals(ForwardingSender::class, $this->storage[0]->getAttributes()->get(MessengerInstrumentation::ATTRIBUTE_MESSENGER_TRANSPORT));
    }

    public function test_message_sent_to_the_sync_transport_creates_a_single_consumer_span()
    {
        $bus = null;
        $syncTransport = new SyncTransport(new RoutableMessageBus(new LazyContainer(['bus' => static function () use (&$bus) {
            return $bus;
        }])));
        $bus = new MessageBus([
            new SendMessageMiddleware(new SendersLocator(
                [SendEmailMessage::class => ['sync']],
                new LazyContainer(['sync' => static fn () => $syncTransport]),
            )),
            new HandleMessageMiddleware(new HandlersLocator([SendEmailMessage::class => [static function (): void {
            }]])),
        ]);

        $bus->dispatch(new SendEmailMessage('Hello Again'), [new BusNameStamp('bus')]);

        $this->assertCount(3, $this->storage);
        $this->assertEquals(
            'CONSUME OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
            $this->storage[0]->getName(),
        );
        $this->assertEquals(
            'SEND OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
            $this->storage[1]->getName(),
        );
        $this->assertEquals(
            'DISPATCH OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
            $this->storage[2]->getName(),
        );
        $this->assertSame($this->storage[1]->getSpanId(), $this->storage[0]->getParentSpanId());
        $this->assertSame($this->storage[2]->getSpanId(), $this->storage[1]->getParentSpanId());
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
