<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\Queue;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\SqsQueue;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Redis\Connections\Connection;
use Mockery\MockInterface;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Jobs\DummyJob;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\TestCase;
use Psr\Log\LoggerInterface;

class QueueTest extends TestCase
{
    private Queue $queue;

    public function setUp(): void
    {
        parent::setUp();

        /** @psalm-suppress PossiblyNullReference */
        $this->queue = $this->app->make(Queue::class);
    }

    public function test_it_handles_pushing_to_a_queue(): void
    {
        $this->queue->push(new DummyJob('A'));
        $this->queue->push(function (LoggerInterface $logger) {
            $logger->info('Logged from closure');
        });

        /** @var \OpenTelemetry\SDK\Logs\ReadWriteLogRecord $logRecord0 */
        $logRecord0 = $this->storage[0];
        $this->assertEquals('Task: A', $logRecord0->getBody());
        $this->assertEquals('sync process', $this->storage[1]->getName());

        /** @var \OpenTelemetry\SDK\Logs\ReadWriteLogRecord $logRecord2 */
        $logRecord2 = $this->storage[2];
        $this->assertEquals('Logged from closure', $logRecord2->getBody());
        $this->assertEquals('sync process', $this->storage[3]->getName());
    }

    public function test_it_can_push_a_message_with_a_delay(): void
    {
        $this->queue->later(15, new DummyJob('int'));
        $this->queue->later(new DateInterval('PT10M'), new DummyJob('DateInterval'));
        $this->queue->later(new DateTimeImmutable('2024-04-15 22:29:00.123Z'), new DummyJob('DateTime'));

        $this->assertEquals('create sync', $this->storage[2]->getName());
        $this->assertIsInt(
            $this->storage[2]->getAttributes()->get('messaging.message.delivery_timestamp'),
        );

        $this->assertEquals('create sync', $this->storage[5]->getName());
        $this->assertIsInt(
            $this->storage[5]->getAttributes()->get('messaging.message.delivery_timestamp'),
        );

        $this->assertEquals('create sync', $this->storage[8]->getName());
        $this->assertIsInt(
            $this->storage[8]->getAttributes()->get('messaging.message.delivery_timestamp'),
        );
    }

    public function test_it_can_publish_in_bulk(): void
    {
        $jobs = [];
        for ($i = 0; $i < 10; ++$i) {
            $jobs[] = new DummyJob("#{$i}");
        }

        /** @var SqsQueue|MockInterface $mockQueue */
        $mockQueue = $this->createMock(SqsQueue::class);
        /** @psalm-suppress UndefinedMethod */
        $mockQueue->method('getQueue')->willReturn('dummy-queue');

        /** @psalm-suppress PossiblyUndefinedMethod */
        $mockQueue->bulk($jobs);

        $this->assertEquals('send dummy-queue', $this->storage[0]->getName());
        $this->assertEquals(10, $this->storage[0]->getAttributes()->get(TraceAttributes::MESSAGING_BATCH_MESSAGE_COUNT));
    }

    public function test_it_can_create_with_redis(): void
    {
        /** @var RedisQueue|MockInterface $mockQueue */
        $mockQueue = $this->createMock(RedisQueue::class);
        /** @psalm-suppress UndefinedMethod */
        $mockQueue->method('getQueue')->willReturn('queues:default');
        /** @psalm-suppress UndefinedMethod */
        $mockQueue
            ->method('getConnection')
            ->willReturn($this->createMock(Connection::class));

        /** @psalm-suppress PossiblyUndefinedMethod */
        $mockQueue->bulk([
            new DummyJob('A'),
            new DummyJob('B'),
        ]);

        $this->assertEquals('send queues:default', $this->storage[0]->getName());
        $this->assertEquals(2, $this->storage[0]->getAttributes()->get(TraceAttributes::MESSAGING_BATCH_MESSAGE_COUNT));
        $this->assertEquals('redis', $this->storage[0]->getAttributes()->get(TraceAttributes::MESSAGING_SYSTEM));
    }

    public function test_it_drops_empty_receives(): void
    {
        $mockQueueManager = $this->createMock(QueueManager::class);

        $mockQueueManager->method('connection')
            ->with('sqs')
            ->willReturn($this->createMock(SqsQueue::class));

        /**
         * @psalm-suppress PossiblyNullReference
         * @var Worker $worker
         */
        $worker = $this->app->make(Worker::class, [
            'manager' => $mockQueueManager,
            'isDownForMaintenance' => fn () => false,
        ]);

        $receive = fn () => $worker->runNextJob('sqs', 'default', new WorkerOptions(sleep: 0));

        for ($i = 0; $i < 1000; $i++) {
            if ($i % 10 === 0) {
                $this->queue->push(new DummyJob("{$i}"));
            }

            $receive();
        }

        for ($i = 0; $i < 2; $i++) {
            $this->queue->push(new DummyJob('More work'));
            $receive();
        }

        /** @psalm-suppress PossiblyInvalidMethodCall */
        $this->assertEquals(204, $this->storage->count());

        /** @var \OpenTelemetry\SDK\Logs\ReadWriteLogRecord $logRecord100 */
        $logRecord100 = $this->storage[100];
        $this->assertEquals('Task: 500', $logRecord100->getBody());

        /** @var \OpenTelemetry\SDK\Logs\ReadWriteLogRecord $logRecord200 */
        $logRecord200 = $this->storage[200];
        $this->assertEquals('Task: More work', $logRecord200->getBody());
    }
}
