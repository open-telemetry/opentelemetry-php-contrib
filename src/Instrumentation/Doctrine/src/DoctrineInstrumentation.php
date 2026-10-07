<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Doctrine;

use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use Throwable;

class DoctrineInstrumentation
{
    public const NAME = 'doctrine';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.doctrine');
        $tracker = new DoctrineTracker();

        hook(
            \Doctrine\DBAL\Driver::class,
            'connect',
            pre: static function (\Doctrine\DBAL\Driver $driver, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'Doctrine\DBAL\Driver::connect', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(ServerAttributes::SERVER_ADDRESS, AttributesResolver::get(ServerAttributes::SERVER_ADDRESS, func_get_args()))
                    ->setAttribute(ServerAttributes::SERVER_PORT, AttributesResolver::get(ServerAttributes::SERVER_PORT, func_get_args()))
                    ->setAttribute(DbAttributes::DB_SYSTEM_NAME, AttributesResolver::get(DbAttributes::DB_SYSTEM_NAME, func_get_args()))
                    ->setAttribute(DbAttributes::DB_NAMESPACE, AttributesResolver::get(DbAttributes::DB_NAMESPACE, func_get_args()));
                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (\Doctrine\DBAL\Driver $driver, array $params, ?\Doctrine\DBAL\Driver\Connection $connection, ?Throwable $exception) {
                self::end($exception);
            }
        );

        hook(
            \Doctrine\DBAL\Driver\Connection::class,
            'query',
            pre: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, AttributesResolver::getDbQuerySummary($params), $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
                $builder->setAttribute(DbAttributes::DB_QUERY_TEXT, AttributesResolver::get(DbAttributes::DB_QUERY_TEXT, func_get_args()));
                $builder->setAttribute(DbAttributes::DB_OPERATION_NAME, AttributesResolver::getDbOperationName($params));
                $builder->setAttribute(DbAttributes::DB_COLLECTION_NAME, AttributesResolver::getTarget($params));
                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, mixed $statement, ?Throwable $exception) {
                self::end($exception);
            }
        );

        hook(
            \Doctrine\DBAL\Driver\Connection::class,
            'exec',
            pre: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, AttributesResolver::getDbQuerySummary($params), $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(DbAttributes::DB_QUERY_TEXT, AttributesResolver::get(DbAttributes::DB_QUERY_TEXT, func_get_args()))
                    ->setAttribute(DbAttributes::DB_OPERATION_NAME, AttributesResolver::getDbOperationName($params))
                    ->setAttribute(DbAttributes::DB_COLLECTION_NAME, AttributesResolver::getTarget($params));
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, mixed $statement, ?Throwable $exception) {
                self::end($exception);
            }
        );

        hook(
            \Doctrine\DBAL\Driver\Connection::class,
            'prepare',
            pre: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, AttributesResolver::getDbQuerySummary($params), $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(DbAttributes::DB_QUERY_TEXT, AttributesResolver::get(DbAttributes::DB_QUERY_TEXT, func_get_args()))
                    ->setAttribute(DbAttributes::DB_OPERATION_NAME, 'prepare');
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, ?Statement $statement, ?Throwable $exception) use ($tracker) {
                if ($statement) {
                    $scope = Context::storage()->scope();
                    $context = $scope?->context();
                    if ($context) {
                        $span = Span::fromContext($context);
                        $tracker->trackStatement($statement, $span->getContext());
                    }
                }
                self::end($exception);
            }
        );

        hook(
            \Doctrine\DBAL\Driver\Connection::class,
            'beginTransaction',
            pre: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'Doctrine::beginTransaction', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(DbAttributes::DB_OPERATION_NAME, 'begin');
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, mixed $statement, ?Throwable $exception) {
                self::end($exception);
            }
        );

        hook(
            \Doctrine\DBAL\Driver\Connection::class,
            'commit',
            pre: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'Doctrine::commit', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(DbAttributes::DB_OPERATION_NAME, 'commit');
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, mixed $statement, ?Throwable $exception) {
                self::end($exception);
            }
        );

        hook(
            \Doctrine\DBAL\Driver\Connection::class,
            'rollBack',
            pre: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'Doctrine::rollBack', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(DbAttributes::DB_OPERATION_NAME, 'rollback');
                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, mixed $statement, ?Throwable $exception) {
                self::end($exception);
            }
        );

        hook(
            \Doctrine\DBAL\Driver\Statement::class,
            'execute',
            pre: static function (\Doctrine\DBAL\Driver\Statement $statement, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $tracker) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'Doctrine::execute', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(DbAttributes::DB_OPERATION_NAME, 'execute');
                if ($ctx = $tracker->getSpanContextForStatement($statement)) {
                    $builder->addLink($ctx);
                }
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (\Doctrine\DBAL\Driver\Statement $statement, array $params, ?ResultInterface $result, ?Throwable $exception) {
                self::end($exception);
            }
        );
    }

    private static function makeBuilder(
        CachedInstrumentation $instrumentation,
        string $name,
        string $function,
        string $class,
        ?string $filename,
        ?int $lineno
    ): SpanBuilderInterface {
        /** @psalm-suppress ArgumentTypeCoercion */
        return $instrumentation->tracer()
                    ->spanBuilder($name)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);
    }
    private static function end(?Throwable $exception): void
    {
        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }
        $scope->detach();
        $span = Span::fromContext($scope->context());
        if ($exception) {
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        }

        $span->end();
    }
}
