<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;

class QueueWatcher extends Watcher
{
    public function __construct(
        private CachedInstrumentation $instrumentation,
    ) {
    }

    /** @psalm-suppress UndefinedInterfaceMethod */
    public function register(Application $app): void
    {
        Queue::createPayloadUsing(function () {
            $opentelemetry = [];
            TraceContextPropagator::getInstance()->inject($opentelemetry);

            return [
                'opentelemetry' => $opentelemetry,
            ];
        });

        $app['events']->listen(JobProcessing::class, [$this, 'handleJobProcessing']);
        $app['events']->listen(JobProcessed::class, [$this, 'handleJobProcessed']);
    }

    public function handleJobProcessing(JobProcessing $jobProcessing): void
    {
        $parent = TraceContextPropagator::getInstance()->extract(
            $jobProcessing->job->payload()['opentelemetry'] ?? [],
        );

        $span = $this->instrumentation
            ->tracer()
            ->spanBuilder('queue')
            ->setSpanKind(SpanKind::KIND_CONSUMER)
            ->setParent($parent)
            ->startSpan();

        $span->setAttributes([
            TraceAttributes::MESSAGING_SYSTEM => $jobProcessing->job->getConnectionName(),
            TraceAttributes::MESSAGING_DESTINATION_NAME => $jobProcessing->job->getQueue(),
            TraceAttributes::MESSAGING_MESSAGE_ID => $jobProcessing->job->uuid(),
            TraceAttributes::MESSAGING_MESSAGE_ENVELOPE_SIZE => strlen($jobProcessing->job->getRawBody()),
        ]);

        $span->addEvent('job', [
            'id' => $jobProcessing->job->getJobId(),
            'name' => $jobProcessing->job->resolveName(),
            'attempts' => $jobProcessing->job->attempts(),
            'maxExceptions' => $jobProcessing->job->maxExceptions(),
            'maxTries' => $jobProcessing->job->maxTries(),
            'retryUntil' => $jobProcessing->job->retryUntil(),
            'timeout' => $jobProcessing->job->timeout(),
        ]);

        Context::storage()->attach($span->storeInContext($parent));
    }

    public function handleJobProcessed(JobProcessed $jobProcessed): void
    {
        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }
        $scope->detach();
        $span = Span::fromContext($scope->context());

        $span->addEvent('processed', [
            'deleted' => $jobProcessed->job->isDeleted(),
            'released' => $jobProcessed->job->isReleased(),
        ]);

        $span->end();
    }
}
