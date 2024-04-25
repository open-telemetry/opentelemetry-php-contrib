<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue;

use Illuminate\Queue\SyncQueue as LaravelSyncQueue;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHookTrait;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\PostHookTrait;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

class SyncQueue implements LaravelHook
{
    use AttributesBuilder;
    use LaravelHookTrait;
    use PostHookTrait;

    public function instrument(): void
    {
        $this->hookPush();
    }

    protected function hookPush(): bool
    {
        return hook(
            LaravelSyncQueue::class,
            'push',
            pre: function (LaravelSyncQueue $queue, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $span = $this->instrumentation
                    ->tracer()
                    ->spanBuilder(vsprintf('%s %s', [
                        $queue->getConnectionName(),
                        'process',
                    ]))
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttributes([
                        TraceAttributes::CODE_FUNCTION => $function,
                        TraceAttributes::CODE_NAMESPACE => $class,
                        TraceAttributes::CODE_FILEPATH => $filename,
                        TraceAttributes::CODE_LINENO => $lineno,
                    ])
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: function (LaravelSyncQueue $queue, array $params, mixed $returnValue, ?Throwable $exception) {
                $this->endSpan($exception);
            },
        );
    }
}
