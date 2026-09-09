<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Console;

use Illuminate\Console\Command as IlluminateCommand;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHookTrait;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\PostHookTrait;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use Throwable;

class Command implements LaravelHook
{
    use LaravelHookTrait;
    use PostHookTrait;

    public function instrument(): void
    {
        $this->hookExecute();
    }

    /** @psalm-suppress PossiblyUnusedReturnValue  */
    protected function hookExecute(): bool
    {
        return hook(
            IlluminateCommand::class,
            'execute',
            pre: function (IlluminateCommand $command, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                // A worker / daemon command (queue:work, horizon, a custom consumer loop) only
                // returns when the process stops, so its span would never end and would become
                // the ambient parent for every job the process handles. Skip it entirely.
                if (LongRunningCommands::matches($command)) {
                    return;
                }

                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $this->instrumentation
                    ->tracer()
                    ->spanBuilder(sprintf('Command %s', $command->getName() ?: 'unknown'))
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);

                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            post: function (IlluminateCommand $command, array $params, ?int $exitCode, ?Throwable $exception) {
                // Re-check rather than track state: pre skipped this command, so there is no
                // span of ours to end and the current scope belongs to something else.
                if (LongRunningCommands::matches($command)) {
                    return;
                }

                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }

                $span = Span::fromContext($scope->context());
                $span->addEvent('command finished', [
                    'exit-code' => $exitCode,
                ]);

                if ($exitCode !== IlluminateCommand::SUCCESS) {
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }

                $this->endSpan($exception);
            }
        );
    }
}
