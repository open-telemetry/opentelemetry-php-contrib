<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Database\Eloquent;

use Illuminate\Database\Eloquent\Model as EloquentModel;
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
class Model implements Hook
{
    use PostHookTrait;

    public function instrument(
        LaravelConfiguration $configuration,
        HookManagerInterface $hookManager,
        InstrumentationContext $context,
    ): void {
        $tracer = $context->tracerProvider->getTracer(
            LaravelInstrumentation::buildProviderName('database', 'eloquent', 'model'),
            schemaUrl: Version::VERSION_1_24_0->url(),
        );

        $this->hookFind($hookManager, $tracer);
        $this->hookPerformInsert($hookManager, $tracer);
        $this->hookPerformUpdate($hookManager, $tracer);
        $this->hookDelete($hookManager, $tracer);
        $this->hookGetModels($hookManager, $tracer);
        $this->hookDestroy($hookManager, $tracer);
        $this->hookRefresh($hookManager, $tracer);
    }

    private function hookFind(HookManagerInterface $hookManager, TracerInterface $tracer): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        $hookManager->hook(
            \Illuminate\Database\Eloquent\Builder::class,
            'find',
            preHook: function ($builder, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($tracer) {
                $model = $builder->getModel();
                $builder = $tracer
                    ->spanBuilder($model::class . '::find')
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute('laravel.eloquent.model', $model::class)
                    ->setAttribute('laravel.eloquent.table', $model->getTable())
                    ->setAttribute('laravel.eloquent.operation', 'find');

                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            postHook: function ($builder, array $params, $result, ?Throwable $exception) {
                $this->endSpan($exception);
            }
        );
    }

    private function hookPerformUpdate(HookManagerInterface $hookManager, TracerInterface $tracer): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        $hookManager->hook(
            EloquentModel::class,
            'performUpdate',
            preHook: function (EloquentModel $model, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($tracer) {
                $builder = $tracer
                    ->spanBuilder($model::class . '::update')
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute('laravel.eloquent.model', $model::class)
                    ->setAttribute('laravel.eloquent.table', $model->getTable())
                    ->setAttribute('laravel.eloquent.operation', 'update');

                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            postHook: function (EloquentModel $model, array $params, $result, ?Throwable $exception) {
                $this->endSpan($exception);
            }
        );
    }

    private function hookPerformInsert(HookManagerInterface $hookManager, TracerInterface $tracer): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        $hookManager->hook(
            EloquentModel::class,
            'performInsert',
            preHook: function (EloquentModel $model, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($tracer) {
                $builder = $tracer
                    ->spanBuilder($model::class . '::create')
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute('laravel.eloquent.model', $model::class)
                    ->setAttribute('laravel.eloquent.table', $model->getTable())
                    ->setAttribute('laravel.eloquent.operation', 'create');

                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            postHook: function (EloquentModel $model, array $params, $result, ?Throwable $exception) {
                $this->endSpan($exception);
            }
        );
    }

    private function hookDelete(HookManagerInterface $hookManager, TracerInterface $tracer): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        $hookManager->hook(
            EloquentModel::class,
            'delete',
            preHook: function (EloquentModel $model, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($tracer) {
                $builder = $tracer
                    ->spanBuilder($model::class . '::delete')
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute('laravel.eloquent.model', $model::class)
                    ->setAttribute('laravel.eloquent.table', $model->getTable())
                    ->setAttribute('laravel.eloquent.operation', 'delete');

                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            postHook: function (EloquentModel $model, array $params, $result, ?Throwable $exception) {
                $this->endSpan($exception);
            }
        );
    }

    private function hookGetModels(HookManagerInterface $hookManager, TracerInterface $tracer): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        $hookManager->hook(
            \Illuminate\Database\Eloquent\Builder::class,
            'getModels',
            preHook: function ($builder, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($tracer) {
                $model = $builder->getModel();
                $builder = $tracer
                    ->spanBuilder($model::class . '::get')
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute('laravel.eloquent.model', $model::class)
                    ->setAttribute('laravel.eloquent.table', $model->getTable())
                    ->setAttribute('laravel.eloquent.operation', 'get')
                    ->setAttribute('db.statement', $builder->getQuery()->toSql());

                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            postHook: function ($builder, array $params, $result, ?Throwable $exception) {
                $this->endSpan($exception);
            }
        );
    }

    private function hookDestroy(HookManagerInterface $hookManager, TracerInterface $tracer): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        $hookManager->hook(
            EloquentModel::class,
            'destroy',
            preHook: function (string $modelClassName, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($tracer) {
                // The class-string is passed to the $model argument, because \Illuminate\Database\Eloquent\Model::destroy is static method.
                // Therefore, create a class instance from a class-string, and then get the table name from the getTable function.
                $model = new $modelClassName();

                $builder = $tracer
                    ->spanBuilder($model::class . '::destroy')
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute('laravel.eloquent.model', $model::class)
                    ->setAttribute('laravel.eloquent.table', $model->getTable())
                    ->setAttribute('laravel.eloquent.operation', 'destroy');

                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            postHook: function ($model, array $params, $result, ?Throwable $exception) {
                $this->endSpan($exception);
            }
        );
    }

    private function hookRefresh(HookManagerInterface $hookManager, TracerInterface $tracer): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        $hookManager->hook(
            EloquentModel::class,
            'refresh',
            preHook: function (EloquentModel $model, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($tracer) {
                $builder = $tracer
                    ->spanBuilder($model::class . '::refresh')
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute('laravel.eloquent.model', $model::class)
                    ->setAttribute('laravel.eloquent.table', $model->getTable())
                    ->setAttribute('laravel.eloquent.operation', 'refresh');

                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            postHook: function (EloquentModel $model, array $params, $result, ?Throwable $exception) {
                $this->endSpan($exception);
            }
        );
    }
}
