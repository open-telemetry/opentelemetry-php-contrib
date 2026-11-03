<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\Queue;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\SqsQueue;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Redis\Connections\Connection;
use Mockery\MockInterface;
use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Globals;
use OpenTelemetry\SemConv\Incubating\Attributes\MessagingIncubatingAttributes;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Jobs\DummyJob;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\TestCase;
use Orchestra\Testbench\Attributes\WithMigration;
use Psr\Log\LoggerInterface;

/** @psalm-suppress UnusedClass */
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

        $this->assertEquals('Task: A', $this->logStorage[0]->getBody());
        $this->assertEquals('sync process', $this->spanStorage[0]->getName());

        $this->assertEquals('Logged from closure', $this->logStorage[1]->getBody());
        $this->assertEquals('sync process', $this->spanStorage[1]->getName());
    }

    public function test_it_can_push_a_message_with_a_delay(): void
    {
        $this->queue->later(15, new DummyJob('int'));
        $this->queue->later(new DateInterval('PT10M'), new DummyJob('DateInterval'));
        $this->queue->later(new DateTimeImmutable('2024-04-15 22:29:00.123Z'), new DummyJob('DateTime'));

        $this->assertEquals('create sync', $this->spanStorage[1]->getName());
        $this->assertIsInt(
            $this->spanStorage[1]->getAttributes()->get('messaging.message.delivery_timestamp'),
        );

        $this->assertEquals('create sync', $this->spanStorage[3]->getName());
        $this->assertIsInt(
            $this->spanStorage[3]->getAttributes()->get('messaging.message.delivery_timestamp'),
        );

        $this->assertEquals('create sync', $this->spanStorage[5]->getName());
        $this->assertIsInt(
            $this->spanStorage[5]->getAttributes()->get('messaging.message.delivery_timestamp'),
        );
    }

    public function test_it_uses_correct_queue_name_when_later_is_called_with_explicit_queue(): void
    {
        /** @var SqsQueue|MockInterface $mockQueue */
        $mockQueue = $this->createMock(SqsQueue::class);
        /** @psalm-suppress UndefinedMethod */
        $mockQueue->method('getQueue')->with('custom-queue')->willReturn('custom-queue');

        /** @psalm-suppress PossiblyUndefinedMethod */
        $mockQueue->later(15, new DummyJob('test'), '', 'custom-queue');

        $this->assertEquals('create custom-queue', $this->spanStorage[0]->getName());
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

        $this->assertEquals('send dummy-queue', $this->spanStorage[0]->getName());
        $this->assertEquals(10, $this->spanStorage[0]->getAttributes()->get(MessagingIncubatingAttributes::MESSAGING_BATCH_MESSAGE_COUNT));
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

        $this->assertEquals('send queues:default', $this->spanStorage[0]->getName());
        $this->assertEquals(2, $this->spanStorage[0]->getAttributes()->get(MessagingIncubatingAttributes::MESSAGING_BATCH_MESSAGE_COUNT));
        $this->assertEquals('redis', $this->spanStorage[0]->getAttributes()->get(MessagingIncubatingAttributes::MESSAGING_SYSTEM));
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

        for ($i = 0; $i < 3; $i++) {
            if ($i % 2 === 0) {
                $this->queue->push(new DummyJob("{$i}"));
            }

            $receive();
        }

        $this->queue->push(new DummyJob('More work'));
        $receive();

        /** @psalm-suppress PossiblyInvalidMethodCall */
        $this->assertEquals(7, $this->spanStorage->count());
        $this->assertEquals('Task: 0', $this->logStorage[0]->getBody());
        $this->assertEquals('Task: 2', $this->logStorage[1]->getBody());
        $this->assertEquals('Task: More work', $this->logStorage[2]->getBody());
    }

    #[WithMigration('queue')]
    public function test_queue_job_propagation(): void
    {
        $this->artisan('migrate');

        $capturedPayload = null;
        $this->app['events']->listen(JobQueueing::class, function (JobQueueing $job) use (&$capturedPayload) {
            $capturedPayload = json_decode($job->payload, true, JSON_THROW_ON_ERROR);
        });

        $parentSpan = Globals::tracerProvider()
            ->getTracer('testing')
            ->spanBuilder('init')
            ->startSpan();
        $parentScope = $parentSpan->activate();
        $baggage = Baggage::getBuilder()
            ->set('user.id', '12345')
            ->set('session.key', 'abc-xyz')
            ->build();
        $baggageScope = $baggage->activate();

        $queue = tap($this->app->make(DatabaseQueue::class, [
            'table' => 'jobs',
        ]), fn (DatabaseQueue $queue) => $queue->setContainer($this->app));

        try {
            $queue->push(new DummyJob('foo'));

            self::assertEquals('user.id=12345,session.key=abc-xyz', $capturedPayload['baggage']);
            self::assertNotEmpty($capturedPayload['traceparent']);
        } finally {
            $baggageScope->detach();
            $parentSpan->end();
            $parentScope->detach();
        }
    }
}
