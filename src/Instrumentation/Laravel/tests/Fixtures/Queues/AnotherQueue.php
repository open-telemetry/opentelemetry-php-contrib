<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Queues;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;

class AnotherQueue extends Queue implements QueueContract
{
    public function getQueue($queue)
    {
        return 'another-queue-name';
    }

    public function size($queue = null)
    {
        // dummy
    }

    public function push($job, $data = '', $queue = null)
    {
        // dummy
    }

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        // dummy
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        // dummy
    }

    public function pop($queue = null)
    {
        // dummy
    }
}
