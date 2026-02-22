<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue;

use Illuminate\Queue\SyncQueue as LaravelSyncQueue;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Context as InstrumentationContext;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Hook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\PostHookTrait;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelConfiguration;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelInstrumentation;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Version;
use Throwable;

/** @psalm-suppress UnusedClass */
class SyncQueue implements Hook
{
    use AttributesBuilder;
    use PostHookTrait;

    public function instrument(
        LaravelConfiguration $configuration,
        HookManagerInterface $hookManager,
        InstrumentationContext $context,
    ): void {
        $tracer = $context->tracerProvider->getTracer(
            LaravelInstrumentation::buildProviderName('queue', 'sync'),
            schemaUrl: Version::VERSION_1_24_0->url(),
        );

        $this->hookPush($hookManager, $tracer);
    }

    /** @psalm-suppress PossiblyUnusedReturnValue */
    protected function hookPush(HookManagerInterface $hookManager, TracerInterface $tracer): void
    {
        $hookManager->hook(
            LaravelSyncQueue::class,
            'push',
            preHook: function (LaravelSyncQueue $queue, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($tracer) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $span = $tracer
                    ->spanBuilder(vsprintf('%s %s', [
                        $queue->getConnectionName(),
                        'process',
                    ]))
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttributes([
                        CodeAttributes::CODE_FUNCTION_NAME => sprintf('%s::%s', $class, $function),
                        CodeAttributes::CODE_FILE_PATH => $filename,
                        CodeAttributes::CODE_LINE_NUMBER => $lineno,
                    ])
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            postHook: function (LaravelSyncQueue $queue, array $params, mixed $returnValue, ?Throwable $exception) {
                $this->endSpan($exception);
            },
        );
    }
}
