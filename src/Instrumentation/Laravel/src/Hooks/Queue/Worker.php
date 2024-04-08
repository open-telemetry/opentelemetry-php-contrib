<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Queue;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Worker as QueueWorker;
use Illuminate\Queue\WorkerOptions;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\HookInstance;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

class Worker
{
    use AttributesBuilder;
    use HookInstance;

    public function instrument(): void
    {
        $this->hookWorkerProcess();
    }

    private function hookWorkerProcess(): bool
    {
        return hook(
            QueueWorker::class,
            'process',
            pre: function (QueueWorker $worker, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                $connectionName = (is_string($params[0] ?? null) ? $params[0] : null);
                $job = ($params[1] instanceof Job ? $params[1] : null);
                $workerOptions = ($params[2] instanceof WorkerOptions ? $params[2] : null);

                $parent = TraceContextPropagator::getInstance()->extract(
                    $job?->payload() ?? [],
                );

                $queue = $worker->getManager()->connection($connectionName);
                $attributes = $this->buildMessageAttributes($queue, $job->getRawBody(), $job->getQueue());

                $span = $this->instrumentation
                    ->tracer()
                    ->spanBuilder(vsprintf('%s %s', [
                        $attributes[TraceAttributes::MESSAGING_DESTINATION_NAME] ?? '(anonymous)',
                        'process',
                    ]))
                    ->setSpanKind(SpanKind::KIND_CONSUMER)
                    ->setParent($parent)
                    ->startSpan()
                    ->setAttributes($attributes);

                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            post: function (QueueWorker $worker, array $params, $returnValue, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }

                $scope->detach();
                $span = Span::fromContext($scope->context());
                if ($exception) {
                    $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $connectionName = (is_string($params[0] ?? null) ? $params[0] : null);
                $job = ($params[1] instanceof Job ? $params[1] : null);
                $workerOptions = ($params[2] instanceof WorkerOptions ? $params[2] : null);

                $span->setAttributes([
                    'messaging.message.deleted' => $job?->isDeleted(),
                    'messaging.message.released' => $job?->isReleased(),
                ]);

                $span->end();
            },
        );
    }
}
