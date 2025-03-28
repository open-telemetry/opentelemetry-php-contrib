<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\View;

use Illuminate\View\View as LaravelView;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHookTrait;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\PostHookTrait;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelInstrumentation;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

/**
 * Enhanced instrumentation for Laravel's view rendering.
 */
class View implements LaravelHook
{
    use LaravelHookTrait;
    use PostHookTrait;

    public function instrument(): void
    {
        $this->hookRender();
    }

    /**
     * Hook into View::render to track view rendering.
     */
    protected function hookRender(): bool
    {
        return hook(
            LaravelView::class,
            'render',
            pre: function (LaravelView $view, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $this->instrumentation
                    ->tracer()
                    ->spanBuilder('laravel.view.render')
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute('view.name', $view->getName());

                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            post: function (LaravelView $view, array $params, mixed $response, ?Throwable $exception) {
                $this->endSpan($exception);
            }
        );
    }
} 