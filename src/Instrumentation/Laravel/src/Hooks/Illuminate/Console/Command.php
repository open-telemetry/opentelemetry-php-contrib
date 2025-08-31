<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Console;

use Illuminate\Console\Command as IlluminateCommand;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Context as InstrumentationContext;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Hook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\PostHookTrait;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelInstrumentation;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\Version;
use Throwable;

class Command implements Hook
{
    use PostHookTrait;

    public function instrument(
        LaravelInstrumentation $instrumentation,
        HookManagerInterface $hookManager,
        InstrumentationContext $context,
    ): void {
        $tracer = $context->tracerProvider->getTracer(
            $instrumentation->buildProviderName('console', 'command'),
            schemaUrl: Version::VERSION_1_24_0->url(),
        );

        $this->hookExecute($hookManager, $tracer);
    }

    /** @psalm-suppress PossiblyUnusedReturnValue  */
    protected function hookExecute(HookManagerInterface $hookManager, TracerInterface $tracer): void
    {
        $hookManager->hook(
            IlluminateCommand::class,
            'execute',
            preHook: function (IlluminateCommand $command, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($tracer) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $tracer
                    ->spanBuilder(sprintf('Command %s', $command->getName() ?: 'unknown'))
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno);

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
