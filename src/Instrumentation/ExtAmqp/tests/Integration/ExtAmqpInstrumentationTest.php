<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\ExtAmqp\tests\Integration;

use AMQPQueue;
use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\TraceAttributes;
use PHPUnit\Framework\TestCase;

class ExtAmqpInstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    /**
     * @var ImmutableSpan[]
     */
    private ArrayObject $storage;
    private ImmutableSpan $span;

    public function setUp(): void
    {
        $this->storage = new ArrayObject();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage)
            )
        );

        $this->scope = Configurator::create()
            ->withTracerProvider($tracerProvider)
            ->activate();
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    public function test_rabbit_basic_publish(): void
    {
        $routing_key = 'my_queue';

        $connection = $this->getRabbitConnection();
        $channel = new \AMQPChannel($connection);
        $exchange = new \AMQPExchange($channel);

        $queue = new AMQPQueue($channel);
        $queue->setName($routing_key);
        $queue->setFlags(AMQP_NOPARAM);
        $queue->declareQueue();

        $exchange->publish('test', $routing_key);

        $this->assertCount(1, $this->storage);
        $this->assertEquals($routing_key . ' publish', $this->storage[0]->getName());
        $this->assertEquals('rabbitmq', $this->storage[0]->getAttributes()->get(TraceAttributes::MESSAGING_SYSTEM));

        $connection->disconnect();
    }

    public function getRabbitConnection(): \AMQPConnection
    {
        $rabbitHost = getenv('RABBIT_HOST') ?: 'localhost';

        $conn = new \AMQPConnection();
        $conn->setHost($rabbitHost);
        $conn->setPort(5672);
        $conn->setLogin('guest');
        $conn->setPassword('guest');
        $conn->connect();

        return $conn;
    }
}
