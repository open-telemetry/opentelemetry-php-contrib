<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\Queue;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\Queue;
use Mockery\MockInterface;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Common\Time\ClockInterface;
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

    /**
     * @test
     */
    public function it_handles_pushing_to_a_queue(): void
    {
        $this->queue->push(new DummyJob('A'));
        $this->queue->push(function (LoggerInterface $logger) {
            $logger->info('Logged from closure');
        });

        $this->assertEquals('sync process', $this->storage[0]->getName());
        $this->assertEquals('Task: A', $this->storage[0]->getEvents()[0]->getName());

        $this->assertEquals('sync process', $this->storage[1]->getName());
        $this->assertEquals('Logged from closure', $this->storage[1]->getEvents()[0]->getName());
    }

    /**
     * @test
     */
    public function it_can_push_a_message_with_a_delay(): void
    {
        /** @var ClockInterface|MockInterface $clock */
        $clock = $this->createMock(ClockInterface::class);

        /** @psalm-suppress UndefinedInterfaceMethod */
        $clock
            ->method('now')
            ->willReturn(ClockFactory::create()->build()->now());

        /** @psalm-suppress PossiblyInvalidArgument */
        ClockFactory::setDefault($clock);
        /** @psalm-suppress PossiblyUndefinedMethod */
        $now = new DateTimeImmutable('@' . ($clock->now() / ClockInterface::NANOS_PER_SECOND));

        $this->queue->later(15, new DummyJob('int'));
        $this->queue->later(new DateInterval('PT10M'), new DummyJob('DateInterval'));
        $this->queue->later(new DateTimeImmutable('2024-04-15 22:29:00.123Z'), new DummyJob('DateTime'));

        $this->assertEquals('sync create', $this->storage[1]->getName());
        $this->assertEquals(
            $now->add(new DateInterval('PT15S'))->getTimestamp(),
            $this->storage[1]->getAttributes()->get('messaging.message.delivery_timestamp'),
        );

        $this->assertEquals('sync create', $this->storage[3]->getName());
        $this->assertEquals(
            $now->add(new DateInterval('PT10M'))->getTimestamp(),
            $this->storage[3]->getAttributes()->get('messaging.message.delivery_timestamp'),
        );

        $this->assertEquals('sync create', $this->storage[5]->getName());
        $this->assertEquals(
            1713220140,
            $this->storage[5]->getAttributes()->get('messaging.message.delivery_timestamp'),
        );
    }
}
