<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Contrib\Instrumentation\Symfony\MessengerInstrumentation;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\TestUtils\TraceStructureAssertionTrait;
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

final class TestMessage
{
    public function __construct()
    {
        throw new \RuntimeException('test');
    }
}

final class TestMessageWithRetry
{
    public function __construct()
    {
        throw new \RuntimeException('test');
    }
}

final class MessengerInstrumentationTest extends AbstractTest
{
    use TraceStructureAssertionTrait;
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

        $this->assertTraceStructure(
            $this->storage,
            [
                [
                    'name' => $spanName,
                    'kind' => $kind,
                    'attributes' => $attributes,
                ],
            ]
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

        $this->assertTraceStructure(
            $this->storage,
            [
                [
                    'name' => $spanName,
                    'kind' => $kind,
                    'attributes' => $attributes,
                ],
            ]
        );
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
        } catch (\Exception $e) {
            // Expected exception
            $this->assertEquals('booo!', $e->getMessage());

            // Now check the trace structure
            $this->assertTraceStructure(
                $this->storage,
                [
                    [
                        'name' => $this->stringContains('create'),
                        'kind' => SpanKind::KIND_PRODUCER,
                        'attributes' => [
                            MessengerInstrumentation::ATTRIBUTE_MESSENGER_MESSAGE => 'OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
                            TraceAttributes::MESSAGING_OPERATION_TYPE => 'create',
                        ],
                    ],
                ]
            );
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
            // Expected exception
            $this->assertEquals('booo!', $e->getMessage());

            // Now check the trace structure
            $this->assertTraceStructure(
                $this->storage,
                [
                    [
                        'name' => $this->stringContains('send'),
                        'kind' => SpanKind::KIND_PRODUCER,
                        'attributes' => [
                            MessengerInstrumentation::ATTRIBUTE_MESSENGER_MESSAGE => 'OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
                            TraceAttributes::MESSAGING_OPERATION_TYPE => 'send',
                        ],
                    ],
                ]
            );
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

        // We should have 2 spans: send and consume
        $this->assertTraceStructure(
            $this->storage,
            [
                [
                    'name' => 'send ' . (class_exists('Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport') ? 'Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport' : 'Symfony\Component\Messenger\Transport\InMemoryTransport'),
                    'kind' => SpanKind::KIND_PRODUCER,
                ],
                [
                    'name' => 'receive transport',
                    'kind' => SpanKind::KIND_CONSUMER,
                ],
            ]
        );
    }

    public function test_middleware_instrumentation()
    {
        // Register the instrumentation first
        MessengerInstrumentation::register();

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
            public function next(): \Symfony\Component\Messenger\Middleware\MiddlewareInterface
            {
                return new class() implements \Symfony\Component\Messenger\Middleware\MiddlewareInterface {
                    public function handle(Envelope $envelope, \Symfony\Component\Messenger\Middleware\StackInterface $stack): Envelope
                    {
                        return $envelope;
                    }
                };
            }
        });

        // Use assertTraceStructure with PHPUnit constraints
        $this->assertTraceStructure(
            $this->storage,
            [
                [
                    'name' => $this->logicalAnd(
                        $this->stringStartsWith('middleware'),
                        $this->stringContains('SendEmailMessage')
                    ),
                    'kind' => SpanKind::KIND_INTERNAL,
                    'attributes' => [
                        MessengerInstrumentation::ATTRIBUTE_MESSAGING_MIDDLEWARE => $this->logicalNot($this->isEmpty()),
                    ],
                ],
            ]
        );
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
        $this->assertTraceStructure(
            $this->storage,
            [
                [
                    'name' => 'send ' . (class_exists('Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport') ? 'Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport' : 'Symfony\Component\Messenger\Transport\InMemoryTransport'),
                    'kind' => SpanKind::KIND_PRODUCER,
                    'attributes' => [
                        'messaging.symfony.bus' => 'test_bus',
                        'messaging.symfony.delay' => 1000,
                        TraceAttributes::MESSAGING_MESSAGE_ID => 'test-id',
                        TraceAttributes::MESSAGING_OPERATION_TYPE => 'send',
                    ],
                ],
            ]
        );

        // Check stamps count
        $sendSpan = $this->storage[0];
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

        $bus = $this->getMessenger();
        $bus->dispatch(new TestMessage());
    }

    public function test_throw_exception_with_retry(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('test');

        $bus = $this->getMessenger();
        $bus->dispatch(new TestMessageWithRetry());
    }

    public function sendDataProvider(): array
    {
        $transportClass = class_exists('Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport')
            ? 'Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport'
            : 'Symfony\Component\Messenger\Transport\InMemoryTransport';

        return [
            [
                new SendEmailMessage('Hello Again'),
                'send ' . $transportClass,
                SpanKind::KIND_PRODUCER,
                [
                    TraceAttributes::MESSAGING_DESTINATION_NAME => $transportClass,
                    MessengerInstrumentation::ATTRIBUTE_MESSENGER_MESSAGE => 'OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
                    TraceAttributes::MESSAGING_OPERATION_TYPE => 'send',
                ],
            ],
        ];
    }

    public function dispatchDataProvider(): array
    {
        return [
            [
                new SendEmailMessage('Hello Again'),
                'create Symfony\Component\Messenger\MessageBus',
                SpanKind::KIND_PRODUCER,
                [
                    MessengerInstrumentation::ATTRIBUTE_MESSENGER_BUS => 'Symfony\Component\Messenger\MessageBus',
                    MessengerInstrumentation::ATTRIBUTE_MESSENGER_MESSAGE => 'OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\SendEmailMessage',
                    TraceAttributes::MESSAGING_DESTINATION_NAME => 'Symfony\Component\Messenger\MessageBus',
                    TraceAttributes::MESSAGING_OPERATION_TYPE => 'create',
                ],
            ],
        ];
    }
}
