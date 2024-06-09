<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Contracts\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as KernelContract;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManager;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Hook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue\AttributesBuilder;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\PostHookTrait;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelConfiguration;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelInstrumentation;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

class Kernel implements Hook
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
        if (LaravelInstrumentation::shouldTraceCli()) {
            $this->hookHandle($hookManager, $tracer);
        }
    }

    private function hookHandle(HookManager $hookManager, TracerInterface $tracer): void
    {
        $hookManager->hook(
            KernelContract::class,
            'handle',
            preHook: function (KernelContract $kernel, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($tracer) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $tracer
                    ->spanBuilder('Artisan handler')
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);

                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            postHook: function (KernelContract $kernel, array $params, ?int $exitCode, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }

                $span = Span::fromContext($scope->context());

                if ($exitCode !== Command::SUCCESS) {
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }

                $this->endSpan($exception);
            }
        );
    }
}
