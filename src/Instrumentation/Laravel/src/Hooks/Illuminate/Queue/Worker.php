<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Worker as QueueWorker;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManager;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Hook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\PostHookTrait;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelConfiguration;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\TraceAttributeValues;
use Throwable;

class Worker implements Hook
{
    use AttributesBuilder;
    use PostHookTrait;

    public function instrument(
        HookManager $hookManager,
        LaravelConfiguration $configuration,
        LoggerInterface $logger,
        MeterInterface $meter,
        TracerInterface $tracer,
    ): void {
        $this->hookWorkerProcess($hookManager, $tracer);
        $this->hookWorkerGetNextJob($hookManager, $tracer);
    }

    private function hookWorkerProcess(HookManager $hookManager, TracerInterface $tracer): void
    {
        $hookManager->hook(
            QueueWorker::class,
            'process',
            preHook: function (QueueWorker $worker, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($tracer) {
                $connectionName = $params[0];
                /** @var Job $job */
                $job = $params[1];

                $parent = TraceContextPropagator::getInstance()->extract(
                    $job->payload(),
                );

                $queue = $worker->getManager()->connection($connectionName);
                $attributes = $this->buildMessageAttributes($queue, $job->getRawBody(), $job->getQueue());

                /** @psalm-suppress ArgumentTypeCoercion */
                $span = $tracer
                    ->spanBuilder(vsprintf('%s %s', [
                        $attributes[TraceAttributes::MESSAGING_DESTINATION_NAME],
                        'process',
                    ]))
                    ->setSpanKind(SpanKind::KIND_CONSUMER)
                    ->setParent($parent)
                    ->setAttributes($attributes)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            postHook: function (QueueWorker $worker, array $params, $returnValue, ?Throwable $exception) {
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

    private function hookWorkerGetNextJob(HookManager $hookManager, TracerInterface $tracer): bool
    {
        $hookManager->hook(
            QueueWorker::class,
            'getNextJob',
            preHook: function (QueueWorker $worker, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($tracer) {
                /** @var \Illuminate\Contracts\Queue\Queue $connection */
                $connection = $params[0];
                $queue = $params[1];

                $attributes = $this->buildMessageAttributes($connection, '', $queue);

                /** @psalm-suppress ArgumentTypeCoercion */
                $span = $tracer
                    ->spanBuilder(vsprintf('%s %s', [
                        $attributes[TraceAttributes::MESSAGING_DESTINATION_NAME],
                        TraceAttributeValues::MESSAGING_OPERATION_RECEIVE,
                    ]))
                    ->setSpanKind(SpanKind::KIND_CONSUMER)
                    ->setAttributes($attributes)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));

                return $params;
            },
            postHook: function (QueueWorker $worker, array $params, ?Job $job, ?Throwable $exception) {
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
