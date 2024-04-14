<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\Queue;

use Illuminate\Contracts\Queue\Queue;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Jobs\DummyJob;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\TestCase;

class QueueTest extends TestCase
{
    /** @test */
    public function it_traces_jobs(): void
    {
        /** @var Queue $queue */
        $queue = $this->app['queue'];

        // Crashes with SIGSEGV
        $queue->push(new DummyJob('A'));
    }
}
