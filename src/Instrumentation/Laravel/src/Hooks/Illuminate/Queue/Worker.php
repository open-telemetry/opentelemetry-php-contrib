<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Worker as QueueWorker;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHookTrait;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\PostHookTrait;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\TraceAttributeValues;
use Throwable;

class Worker implements LaravelHook
{
    use AttributesBuilder;
    use LaravelHookTrait;
    use PostHookTrait;

    public function instrument(): void
    {
        $this->hookWorkerProcess();
        $this->hookWorkerGetNextJob();
    }

    /** @psalm-suppress UnusedReturnValue */
    private function hookWorkerProcess(): bool
    {
        return hook(
            QueueWorker::class,
            'process',
            pre: function (QueueWorker $worker, array $params, string $_class, string $_function, ?string $_filename, ?int $_lineno) {
                $connectionName = $params[0];
                /** @var Job $job */
                $job = $params[1];

                $parent = TraceContextPropagator::getInstance()->extract(
                    $job->payload(),
                );

                $queue = $worker->getManager()->connection($connectionName);
                $attributes = $this->buildMessageAttributes($queue, $job->getRawBody(), $job->getQueue());

                /** @psalm-suppress ArgumentTypeCoercion */
                $span = $this->instrumentation
                    ->tracer()
                    ->spanBuilder(vsprintf('%s %s', [
                        TraceAttributeValues::MESSAGING_OPERATION_TYPE_PROCESS,
                        $attributes[TraceAttributes::MESSAGING_DESTINATION_NAME],
                    ]))
                    ->setSpanKind(SpanKind::KIND_CONSUMER)
                    ->setParent($parent)
                    ->setAttributes($attributes)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            post: function (QueueWorker $worker, array $params, $returnValue, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }

                $span = Span::fromContext($scope->context());
                $job = ($params[1] instanceof Job ? $params[1] : null);

                $span->setAttributes([
                    'messaging.message.deleted' => $job?->isDeleted(),
                    'messaging.message.released' => $job?->isReleased(),
                ]);

                $this->endSpan($exception);
            },
        );
    }

    /** @psalm-suppress UnusedReturnValue */
    private function hookWorkerGetNextJob(): bool
    {
        return hook(
            QueueWorker::class,
            'getNextJob',
            pre: function (QueueWorker $_worker, array $params, string $_class, string $_function, ?string $_filename, ?int $_lineno) {
                /** @var \Illuminate\Contracts\Queue\Queue $connection */
                $connection = $params[0];
                $queue = $params[1];

                $attributes = $this->buildMessageAttributes($connection, '', $queue);

                /** @psalm-suppress ArgumentTypeCoercion */
                $span = $this->instrumentation
                    ->tracer()
                    ->spanBuilder(vsprintf('%s %s', [
                        TraceAttributeValues::MESSAGING_OPERATION_TYPE_RECEIVE,
                        $attributes[TraceAttributes::MESSAGING_DESTINATION_NAME],
                    ]))
                    ->setSpanKind(SpanKind::KIND_CONSUMER)
                    ->setAttributes($attributes)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));

                return $params;
            },
            post: function (QueueWorker $_worker, array $params, ?Job $job, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }

                // Discard empty receives.
                if (!$job) {
                    $scope->detach();

                    return;
                }

                /** @var \Illuminate\Contracts\Queue\Queue $connection */
                $connection = $params[0];
                /** @var string $queue */
                $queue = $params[1];
                $attributes = $this->buildMessageAttributes($connection, $job->getRawBody(), $queue);

                $span = Span::fromContext($scope->context());
                /** @psalm-suppress PossiblyInvalidArgument */
                $span->setAttributes($attributes);

                $this->endSpan($exception);
            },
        );
    }
}
