<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Database\Eloquent;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHookTrait;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\PostHookTrait;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

class Model implements LaravelHook
{
    use LaravelHookTrait;
    use PostHookTrait;

    public function instrument(): void
    {
        $this->hookFind();
        $this->hookPerformInsert();
        $this->hookPerformUpdate();
        $this->hookDelete();
        $this->hookGetModels();
        $this->hookDestroy();
        $this->hookRefresh();
    }

    private function hookFind(): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        hook(
            \Illuminate\Database\Eloquent\Builder::class,
            'find',
            pre: function ($builder, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                $model = $builder->getModel();
                $builder = $this->instrumentation
                    ->tracer()
                    ->spanBuilder($model::class . '::find')
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute('laravel.eloquent.model', $model::class)
                    ->setAttribute('laravel.eloquent.table', $model->getTable())
                    ->setAttribute('laravel.eloquent.operation', 'find');

                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            post: function ($builder, array $params, $result, ?Throwable $exception) {
                $this->endSpan($exception);
            }
        );
    }

    private function hookPerformUpdate(): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        hook(
            EloquentModel::class,
            'performUpdate',
            pre: function (EloquentModel $model, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                $builder = $this->instrumentation
                    ->tracer()
                    ->spanBuilder($model::class . '::update')
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute('laravel.eloquent.model', $model::class)
                    ->setAttribute('laravel.eloquent.table', $model->getTable())
                    ->setAttribute('laravel.eloquent.operation', 'update');

                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            post: function (EloquentModel $model, array $params, $result, ?Throwable $exception) {
                $this->endSpan($exception);
            }
        );
    }

    private function hookPerformInsert(): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        hook(
            EloquentModel::class,
            'performInsert',
            pre: function (EloquentModel $model, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                $builder = $this->instrumentation
                    ->tracer()
                    ->spanBuilder($model::class . '::create')
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute('laravel.eloquent.model', $model::class)
                    ->setAttribute('laravel.eloquent.table', $model->getTable())
                    ->setAttribute('laravel.eloquent.operation', 'create');

                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            post: function (EloquentModel $model, array $params, $result, ?Throwable $exception) {
                $this->endSpan($exception);
            }
        );
    }

    private function hookDelete(): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        hook(
            EloquentModel::class,
            'delete',
            pre: function (EloquentModel $model, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                $builder = $this->instrumentation
                    ->tracer()
                    ->spanBuilder($model::class . '::delete')
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute('laravel.eloquent.model', $model::class)
                    ->setAttribute('laravel.eloquent.table', $model->getTable())
                    ->setAttribute('laravel.eloquent.operation', 'delete');

                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            post: function (EloquentModel $model, array $params, $result, ?Throwable $exception) {
                $this->endSpan($exception);
            }
        );
    }

    private function hookGetModels(): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        hook(
            \Illuminate\Database\Eloquent\Builder::class,
            'getModels',
            pre: function ($builder, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                $model = $builder->getModel();
                $builder = $this->instrumentation
                    ->tracer()
                    ->spanBuilder($model::class . '::get')
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute('laravel.eloquent.model', $model::class)
                    ->setAttribute('laravel.eloquent.table', $model->getTable())
                    ->setAttribute('laravel.eloquent.operation', 'get')
                    ->setAttribute('db.statement', $builder->getQuery()->toSql());

                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            post: function ($builder, array $params, $result, ?Throwable $exception) {
                $this->endSpan($exception);
            }
        );
    }

    private function hookDestroy(): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        hook(
            EloquentModel::class,
            'destroy',
            pre: function (string $modelClassName, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                // The class-string is passed to the $model argument, because \Illuminate\Database\Eloquent\Model::destroy is static method.
                // Therefore, create a class instance from a class-string, and then get the table name from the getTable function.
                $model = new $modelClassName();

                $builder = $this->instrumentation
                    ->tracer()
                    ->spanBuilder($model::class . '::destroy')
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute('laravel.eloquent.model', $model::class)
                    ->setAttribute('laravel.eloquent.table', $model->getTable())
                    ->setAttribute('laravel.eloquent.operation', 'destroy');

                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            post: function ($model, array $params, $result, ?Throwable $exception) {
                $this->endSpan($exception);
            }
        );
    }

    private function hookRefresh(): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        hook(
            EloquentModel::class,
            'refresh',
            pre: function (EloquentModel $model, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                $builder = $this->instrumentation
                    ->tracer()
                    ->spanBuilder($model::class . '::refresh')
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute('laravel.eloquent.model', $model::class)
                    ->setAttribute('laravel.eloquent.table', $model->getTable())
                    ->setAttribute('laravel.eloquent.operation', 'refresh');

                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            post: function (EloquentModel $model, array $params, $result, ?Throwable $exception) {
                $this->endSpan($exception);
            }
        );
    }
}
