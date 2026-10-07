<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\Queue;

use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Jobs\DummyJob;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Jobs\IsolatedJob;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Jobs\LinkedJob;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\TestCase;

/** @psalm-suppress UnusedClass */
class WorkerTest extends TestCase
{
    private Worker $worker;
    private WorkerOptions $workerOptions;

    /** A fixed, valid W3C traceparent representing the "remote" dispatching trace. */
    private const PARENT_TRACEPARENT = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';
    private const PARENT_TRACE_ID = '0af7651916cd43dd8448eb211c80319c';
    private const PARENT_SPAN_ID = 'b7ad6b7169203331';

    public function setUp(): void
    {
        parent::setUp();

        $this->worker = $this->app->make(Worker::class, [
            'isDownForMaintenance' => fn () => false,
        ]);

        $this->workerOptions = new WorkerOptions();
    }

    public function test_job_has_parent_trace_by_default(): void
    {
        $this->dispatchJob(new DummyJob('job-with-parent-trace'));

        $this->assertCount(2, $this->storage);

        $span = $this->findProcessSpan();

        $this->assertSame(self::PARENT_TRACE_ID, $span->getTraceId());
        $this->assertSame(self::PARENT_SPAN_ID, $span->getParentSpanId());
        $this->assertCount(0, $span->getLinks());

        $this->assertSame('Task: job-with-parent-trace', $this->storage[0]->getBody());
        $this->assertSame('process (anonymous)', $this->storage[1]->getName());
        $this->assertSame(DummyJob::class, $span->getAttributes()->get('messaging.message.job_name'));
    }

    public function test_linked_job_has_new_trace_with_link_to_parent(): void
    {
        $this->dispatchJob(new LinkedJob());

        $span = $this->findProcessSpan();

        $this->assertFalse($span->getParentContext()->isValid());
        $this->assertNotSame(self::PARENT_TRACE_ID, $span->getTraceId());

        $this->assertCount(1, $span->getLinks());
        $link = $span->getLinks()[0];
        $this->assertSame(self::PARENT_TRACE_ID, $link->getSpanContext()->getTraceId());
        $this->assertSame(self::PARENT_SPAN_ID, $link->getSpanContext()->getSpanId());

        $this->assertCount(2, $this->storage);
        $this->assertSame('Linked job handled', $this->storage[0]->getBody());
        $this->assertSame('process (anonymous)', $this->storage[1]->getName());
        $this->assertSame(LinkedJob::class, $span->getAttributes()->get('messaging.message.job_name'));
    }

    public function test_isolated_job_starts_completely_new_trace(): void
    {
        $this->dispatchJob(new IsolatedJob());

        $span = $this->findProcessSpan();

        $this->assertFalse($span->getParentContext()->isValid());
        $this->assertNotSame(self::PARENT_TRACE_ID, $span->getTraceId());
        $this->assertCount(0, $span->getLinks());

        $this->assertCount(2, $this->storage);
        $this->assertSame('Isolated job handled', $this->storage[0]->getBody());
        $this->assertSame('process (anonymous)', $this->storage[1]->getName());
        $this->assertSame(IsolatedJob::class, $span->getAttributes()->get('messaging.message.job_name'));
    }

    public function test_plain_job_resolves_interface(): void
    {
        $this->dispatchJob(IsolatedJob::class);

        $span = $this->findProcessSpan();

        $this->assertFalse($span->getParentContext()->isValid());
        $this->assertNotSame(self::PARENT_TRACE_ID, $span->getTraceId());
    }

    public function test_job_with_invalid_traceparent_starts_new_trace(): void
    {
        $this->dispatchJob(new DummyJob('Job with invalid traceparent'), 'invalid');

        $span = $this->findProcessSpan();

        $this->assertFalse($span->getParentContext()->isValid());
        $this->assertNotSame(self::PARENT_TRACE_ID, $span->getTraceId());
    }

    private function dispatchJob(object|string $job, string $traceParent = self::PARENT_TRACEPARENT): SyncJob
    {
        $payload = is_string($job)
            ? [
                'uuid' => 'simple-test-job',
                'job' => $job,
                'displayName' => $job,
                'data' => [],
            ]
            : [
                'uuid' => 'command-test-job',
                'job' => 'Illuminate\Queue\CallQueuedHandler@call',
                'displayName' => get_class($job),
                'data' => [
                    'commandName' => get_class($job),
                    'command' => serialize($job),
                ],
            ];

        // To keep backward compatibility, every job must have a `traceparent`
        $payload['traceparent'] = $traceParent;

        $job = new SyncJob($this->app, json_encode($payload), 'sync', 'default');

        $this->worker->process('sync', $job, $this->workerOptions);

        return $job;
    }

    /**
     * Find the "process" span among all recorded spans in $this->storage.
     * Worker::process emits a span named "process <queue>: <job>".
     *
     * @return \OpenTelemetry\SDK\Trace\ImmutableSpan
     */
    private function findProcessSpan(): \OpenTelemetry\SDK\Trace\ImmutableSpan
    {
        foreach ($this->storage as $item) {
            if (
                $item instanceof \OpenTelemetry\SDK\Trace\ImmutableSpan
                && str_starts_with($item->getName(), 'process ')
            ) {
                return $item;
            }
        }

        $this->fail('No "process *" span was recorded.');
    }
}
