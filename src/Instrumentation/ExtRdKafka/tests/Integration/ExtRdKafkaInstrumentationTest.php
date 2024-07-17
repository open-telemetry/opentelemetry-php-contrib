<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\ExtRdKafka\tests\Integration;

use ArrayObject;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RdKafka\Message;
use RdKafka\Producer;

class ExtRdKafkaInstrumentationTest extends TestCase
{
    private const STANDARD_TOPIC_NAME = 'test';
    private ScopeInterface $scope;
    private ArrayObject $storage;
    private TracerProvider $tracerProvider;

    public function setUp(): void
    {
        $this->storage = new ArrayObject();
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage)
            )
        );
        $this->scope = Configurator::create()
            ->withTracerProvider($this->tracerProvider)
            ->withPropagator(new TraceContextPropagator())
            ->activate();
    }

    public function tearDown(): void
    {
        $this->scope->detach();
        $result = null;
        while ($result !== RD_KAFKA_RESP_ERR__PARTITION_EOF) {
            $result = $this->consumeMessage();
        }
    }

    public function test_consume_creates_new_span(): void
    {
        // Given
        $this->produceMessage('test', produceWithoutHeaders: true);
        $this->assertCount(0, $this->storage);

        // When
        $this->consumeMessage();

        // Then
        $this->assertCount(1, $this->storage);
        /** @var ImmutableSpan $span */
        $span = $this->storage->offsetGet(0);
        $this->assertEquals('test process', $span->getName());
    }

    public function test_context_propagated_on_consumption(): void
    {
        // Given
        $tracerProvider = Globals::tracerProvider();
        $tracer = $tracerProvider->getTracer('test');
        $span = $tracer->spanBuilder('test_span')->startSpan();
        $scope = $span->activate();

        $this->produceMessage('test');

        $scope->detach();
        $span->end();
        $expectedTraceId = $span->getContext()->getTraceId();

        // One span that I had to create in the test, so I can ensure that the message is produced with a TraceID
        // that we know about in the test (for assertions). Another for the actual production of the message
        $this->assertCount(2, $this->storage);

        // When
        // This should create a third span, for consumption, which should have the same TraceID as the first span
        $this->consumeMessage();

        // Then
        $this->assertCount(3, $this->storage);
        /** @var ImmutableSpan $span */
        $span = $this->storage->offsetGet(2);
        $this->assertEquals('test process', $span->getName());
        $this->assertEquals($expectedTraceId, $span->getContext()->getTraceId());
    }

    public function test_context_set_in_kafka_headers_on_message_production(): void
    {
        // Given
        $this->assertCount(0, $this->storage);
        $tracerProvider = Globals::tracerProvider();
        $tracer = $tracerProvider->getTracer('test');
        $span = $tracer->spanBuilder('test_span')->startSpan();
        $scope = $span->activate();

        $this->produceMessage('test');

        $scope->detach();
        $span->end();

        // When
        $message = $this->consumeMessage();

        $this->assertInstanceOf(Message::class, $message);
        $this->assertIsArray($message->headers);
        $this->assertArrayHasKey('traceparent', $message->headers);

        // The traceparent header is separated into 4 sections with dashes. I want to get the second segment of that
        // header, which is the TraceID
        $traceId = explode('-', $message->headers['traceparent'])[1];
        $this->assertEquals($span->getContext()->getTraceId(), $traceId);
    }

    public function test_produce_creates_new_span()
    {
        $this->assertCount(0, $this->storage);
        $this->produceMessage('test');

        $this->assertCount(1, $this->storage);

        $span = $this->storage->offsetGet(0);
        $this->assertEquals('test publish', $span->getName());
    }

    private function produceMessage(
        string $message,
        ?string $key = null,
        array $headers = null,
        bool $produceWithoutHeaders = false
    ): void {
        $conf = new Conf();
        $producer = new Producer($conf);
        $producer->addBrokers(getenv('KAFKA_HOST') ?: 'localhost' . ':9092');

        $topic = $producer->newTopic(self::STANDARD_TOPIC_NAME);

        if ($produceWithoutHeaders) {
            $topic->produce(RD_KAFKA_PARTITION_UA, 0, $message, $key);
        } else {
            $topic->producev(RD_KAFKA_PARTITION_UA, 0, $message, $key, $headers);
        }
        $producer->poll(100);
        for ($flushRetries = 0; $flushRetries < 10; $flushRetries++) {
            $result = $producer->flush(10000);
            if (RD_KAFKA_RESP_ERR_NO_ERROR === $result) {
                break;
            }
        }
    }

    private function consumeMessage(): int|Message
    {
        $conf = new Conf();

        $conf->setRebalanceCb(function (KafkaConsumer $kafka, $err, array $partitions = null) {
            switch ($err) {
                case RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS:
                    $kafka->assign($partitions);

                    break;
                case RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS:
                    $kafka->assign(null);

                    break;
                default:
                    throw new \Exception($err);
            }
        });

        $conf->set('group.id', 'myConsumerGroup');
        $conf->set('metadata.broker.list', getenv('KAFKA_HOST') ?: 'localhost' . ':9092');
        $conf->set('auto.offset.reset', 'earliest');
        $conf->set('enable.partition.eof', 'true');

        $consumer = new KafkaConsumer($conf);

        $consumer->subscribe([self::STANDARD_TOPIC_NAME]);

        while (true) {
            $message = $consumer->consume(50);
            switch ($message->err) {
                case RD_KAFKA_RESP_ERR_NO_ERROR:
                    $consumer->commit($message);

                    return $message;
                case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                    return RD_KAFKA_RESP_ERR__PARTITION_EOF;
                case RD_KAFKA_RESP_ERR__TIMED_OUT:
                    break;
                default:
                    throw new \Exception($message->errstr(), $message->err);
            }
        }
    }
}
