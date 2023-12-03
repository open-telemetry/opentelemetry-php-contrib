<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\ExtAmqp\tests\Integration;

use AMQPQueue;
use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
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
    private ArrayObject $storage;

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
            ->withPropagator(TraceContextPropagator::getInstance())
            ->activate();
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    public function test_rabbit_basic_publish_without_args_works(): void
    {
        $routing_key = uniqid('test_queue_', true);

        $connection = $this->getRabbitConnection();
        $channel = new \AMQPChannel($connection);
        $exchange = new \AMQPExchange($channel);

        $queue = new AMQPQueue($channel);
        $queue->setName($routing_key);
        $queue->setFlags(AMQP_NOPARAM);
        $queue->declareQueue();

        try {
            $exchange->publish('test', $routing_key);

            $this->assertCount(1, $this->storage);

            /** @var ImmutableSpan $span */
            $span = $this->storage[0];

            $this->assertEquals($routing_key . ' publish', $span->getName());
            $this->assertEquals('rabbitmq', $span->getAttributes()->get(TraceAttributes::MESSAGING_SYSTEM));
            $this->assertEquals(SpanKind::KIND_PRODUCER, $span->getKind());

            /**
             * Our message should be the first one in the queue
             */
            $envelope = $queue->get();

            self::assertFalse($envelope->hasHeader('traceparent'), 'traceparent header is present');
        } finally {
            $queue->purge();
            $connection->disconnect();
        }
    }

    public function test_rabbit_basic_publish(): void
    {
        $routing_key = uniqid('test_queue_', true);

        $connection = $this->getRabbitConnection();
        $channel = new \AMQPChannel($connection);
        $exchange = new \AMQPExchange($channel);

        $queue = new AMQPQueue($channel);
        $queue->setName($routing_key);
        $queue->setFlags(AMQP_NOPARAM);
        $queue->declareQueue();

        try {
            $exchange->publish('test', $routing_key, AMQP_NOPARAM, []);

            $this->assertCount(1, $this->storage);

            /** @var ImmutableSpan $span */
            $span = $this->storage[0];

            $this->assertEquals($routing_key . ' publish', $span->getName());
            $this->assertEquals('rabbitmq', $span->getAttributes()->get(TraceAttributes::MESSAGING_SYSTEM));
            $this->assertEquals(SpanKind::KIND_PRODUCER, $span->getKind());

            /**
             * Our message should be the first one in the queue
             */
            $envelope = $queue->get();

            self::assertTrue($envelope->hasHeader('traceparent'), 'traceparent header is missing');
        } finally {
            $queue->purge();
            $connection->disconnect();
        }
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
