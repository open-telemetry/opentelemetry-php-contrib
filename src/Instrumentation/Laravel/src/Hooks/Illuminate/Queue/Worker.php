<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Worker as QueueWorker;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Context as InstrumentationContext;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Hook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\PostHookTrait;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelConfiguration;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelInstrumentation;
use OpenTelemetry\SemConv\Incubating\Attributes\MessagingIncubatingAttributes;
use OpenTelemetry\SemConv\Version;
use Throwable;

/** @psalm-suppress UnusedClass */
class Worker implements Hook
{
    use AttributesBuilder;
    use PostHookTrait;

    public function instrument(
        LaravelConfiguration $configuration,
        HookManagerInterface $hookManager,
        InstrumentationContext $context,
    ): void {
        $tracer = $context->tracerProvider->getTracer(
            LaravelInstrumentation::buildProviderName('queue', 'worker'),
            schemaUrl: Version::VERSION_1_24_0->url(),
        );

        $this->hookWorkerProcess($hookManager, $tracer);
        $this->hookWorkerGetNextJob($hookManager, $tracer);
    }

    /** @psalm-suppress UnusedReturnValue */
    private function hookWorkerProcess(HookManagerInterface $hookManager, TracerInterface $tracer): void
    {
        $hookManager->hook(
            QueueWorker::class,
            'process',
            preHook: function (QueueWorker $worker, array $params, string $_class, string $_function, ?string $_filename, ?int $_lineno) use ($tracer) {
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
                        MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE_VALUE_PROCESS,
                        $attributes[MessagingIncubatingAttributes::MESSAGING_DESTINATION_NAME],
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

    /** @psalm-suppress UnusedReturnValue */
    private function hookWorkerGetNextJob(HookManagerInterface $hookManager, TracerInterface $tracer): void
    {
        $hookManager->hook(
            QueueWorker::class,
            'getNextJob',
            preHook: function (QueueWorker $_worker, array $params, string $_class, string $_function, ?string $_filename, ?int $_lineno) use ($tracer) {
                /** @var \Illuminate\Contracts\Queue\Queue $connection */
                $connection = $params[0];
                $queue = $params[1];

                $attributes = $this->buildMessageAttributes($connection, '', $queue);

                /** @psalm-suppress ArgumentTypeCoercion */
                $span = $tracer
                    ->spanBuilder(vsprintf('%s %s', [
                        MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE_VALUE_RECEIVE,
                        $attributes[MessagingIncubatingAttributes::MESSAGING_DESTINATION_NAME],
                    ]))
                    ->setSpanKind(SpanKind::KIND_CONSUMER)
                    ->setAttributes($attributes)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));

                return $params;
            },
            postHook: function (QueueWorker $_worker, array $params, ?Job $job, ?Throwable $exception) {
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
