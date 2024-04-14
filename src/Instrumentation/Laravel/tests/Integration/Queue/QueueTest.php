<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\Queue;

use Illuminate\Contracts\Queue\Queue;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Jobs\DummyJob;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\TestCase;

class QueueTest extends TestCase
{
    /** @test */
    public function it_handles_pushing_to_a_queue(): void
    {
        /** @var Queue $queue */
        $queue = $this->app['queue'];

        $queue->push(new DummyJob('A'));
        $queue->push(function () {
            logger()->info('Logged from closure');
        });

        $this->assertEquals('sync process', $this->storage[0]->getName());
        $this->assertEquals('Task: A', $this->storage[0]->getEvents()[0]->getName());

        $this->assertEquals('sync process', $this->storage[1]->getName());
        $this->assertEquals('Logged from closure', $this->storage[1]->getEvents()[0]->getName());
    }
}
