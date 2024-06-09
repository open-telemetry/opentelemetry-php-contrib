<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Console;

use Illuminate\Console\Command as IlluminateCommand;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManager;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Hook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\PostHookTrait;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelConfiguration;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

class Command implements Hook
{
    use PostHookTrait;

    public function instrument(
        HookManager $hookManager,
        LaravelConfiguration $configuration,
        LoggerInterface $logger,
        MeterInterface $meter,
        TracerInterface $tracer,
    ): void {
        $this->hookExecute($hookManager, $tracer);
    }

    protected function hookExecute(HookManager $hookManager, TracerInterface $tracer): void
    {
        $hookManager->hook(
            IlluminateCommand::class,
            'execute',
            preHook: function (IlluminateCommand $command, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($tracer) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $tracer
                    ->spanBuilder(sprintf('Command %s', $command->getName() ?: 'unknown'))
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);

                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            postHook: function (IlluminateCommand $command, array $params, ?int $exitCode, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }

                $span = Span::fromContext($scope->context());
                $span->addEvent('command finished', [
                    'exit-code' => $exitCode,
                ]);

                $this->endSpan($exception);
            }
        );
    }
}
