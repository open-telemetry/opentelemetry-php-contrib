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
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

class DoctrineInstrumentation
{
    public const NAME = 'doctrine';

    /**
     * Re-entrancy guard: Doctrine's connection wrapper chain fires hooks at
     * every layer. Only the outermost call creates and ends a span.
     *
     * @var array<string, int>
     */
    private static array $depth = [];

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.doctrine');
        $tracker = new DoctrineTracker();

        hook(
            \Doctrine\DBAL\Driver::class,
            'connect',
            pre: static function (\Doctrine\DBAL\Driver $driver, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation): void {
                self::$depth['connect'] = (self::$depth['connect'] ?? 0) + 1;
                if (self::$depth['connect'] > 1) {
                    return;
                }
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'Doctrine\DBAL\Driver::connect', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::SERVER_ADDRESS, AttributesResolver::get(TraceAttributes::SERVER_ADDRESS, func_get_args()))
                    ->setAttribute(TraceAttributes::SERVER_PORT, AttributesResolver::get(TraceAttributes::SERVER_PORT, func_get_args()))
                    ->setAttribute(TraceAttributes::DB_SYSTEM_NAME, AttributesResolver::get(TraceAttributes::DB_SYSTEM_NAME, func_get_args()))
                    ->setAttribute(TraceAttributes::DB_NAMESPACE, AttributesResolver::get(TraceAttributes::DB_NAMESPACE, func_get_args()));
                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (\Doctrine\DBAL\Driver $driver, array $params, ?\Doctrine\DBAL\Driver\Connection $connection, ?Throwable $exception) {
                self::$depth['connect'] = max(0, (self::$depth['connect'] ?? 1) - 1);
                if (self::$depth['connect'] === 0) {
                    self::end($exception);
                }
            }
        );

        hook(
            \Doctrine\DBAL\Driver\Connection::class,
            'query',
            pre: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation): void {
                self::$depth['query'] = (self::$depth['query'] ?? 0) + 1;
                if (self::$depth['query'] > 1) {
                    return;
                }
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, AttributesResolver::getDbQuerySummary($params), $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
                $builder->setAttribute(TraceAttributes::DB_QUERY_TEXT, AttributesResolver::get(TraceAttributes::DB_QUERY_TEXT, func_get_args()));
                $builder->setAttribute(TraceAttributes::DB_OPERATION_NAME, AttributesResolver::getDbOperationName($params));
                $builder->setAttribute(TraceAttributes::DB_COLLECTION_NAME, AttributesResolver::getTarget($params));
                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, mixed $statement, ?Throwable $exception) {
                self::$depth['query'] = max(0, (self::$depth['query'] ?? 1) - 1);
                if (self::$depth['query'] === 0) {
                    self::end($exception);
                }
            }
        );

        hook(
            \Doctrine\DBAL\Driver\Connection::class,
            'exec',
            pre: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation): void {
                self::$depth['exec'] = (self::$depth['exec'] ?? 0) + 1;
                if (self::$depth['exec'] > 1) {
                    return;
                }
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, AttributesResolver::getDbQuerySummary($params), $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::DB_QUERY_TEXT, AttributesResolver::get(TraceAttributes::DB_QUERY_TEXT, func_get_args()))
                    ->setAttribute(TraceAttributes::DB_OPERATION_NAME, AttributesResolver::getDbOperationName($params))
                    ->setAttribute(TraceAttributes::DB_COLLECTION_NAME, AttributesResolver::getTarget($params));
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, mixed $statement, ?Throwable $exception) {
                self::$depth['exec'] = max(0, (self::$depth['exec'] ?? 1) - 1);
                if (self::$depth['exec'] === 0) {
                    self::end($exception);
                }
            }
        );

        hook(
            \Doctrine\DBAL\Driver\Connection::class,
            'prepare',
            pre: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation): void {
                self::$depth['prepare'] = (self::$depth['prepare'] ?? 0) + 1;
                if (self::$depth['prepare'] > 1) {
                    return;
                }
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, AttributesResolver::getDbQuerySummary($params), $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::DB_QUERY_TEXT, AttributesResolver::get(TraceAttributes::DB_QUERY_TEXT, func_get_args()))
                    ->setAttribute(TraceAttributes::DB_OPERATION_NAME, 'prepare');
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
                self::$depth['prepare'] = max(0, (self::$depth['prepare'] ?? 1) - 1);
                if (self::$depth['prepare'] === 0) {
                    self::end($exception);
                }
            }
        );

        hook(
            \Doctrine\DBAL\Driver\Connection::class,
            'beginTransaction',
            pre: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation): void {
                self::$depth['beginTransaction'] = (self::$depth['beginTransaction'] ?? 0) + 1;
                if (self::$depth['beginTransaction'] > 1) {
                    return;
                }
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'Doctrine::beginTransaction', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::DB_OPERATION_NAME, 'begin');
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, mixed $statement, ?Throwable $exception) {
                self::$depth['beginTransaction'] = max(0, (self::$depth['beginTransaction'] ?? 1) - 1);
                if (self::$depth['beginTransaction'] === 0) {
                    self::end($exception);
                }
            }
        );

        hook(
            \Doctrine\DBAL\Driver\Connection::class,
            'commit',
            pre: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation): void {
                self::$depth['commit'] = (self::$depth['commit'] ?? 0) + 1;
                if (self::$depth['commit'] > 1) {
                    return;
                }
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'Doctrine::commit', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::DB_OPERATION_NAME, 'commit');
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, mixed $statement, ?Throwable $exception) {
                self::$depth['commit'] = max(0, (self::$depth['commit'] ?? 1) - 1);
                if (self::$depth['commit'] === 0) {
                    self::end($exception);
                }
            }
        );

        hook(
            \Doctrine\DBAL\Driver\Connection::class,
            'rollBack',
            pre: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation): void {
                self::$depth['rollBack'] = (self::$depth['rollBack'] ?? 0) + 1;
                if (self::$depth['rollBack'] > 1) {
                    return;
                }
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'Doctrine::rollBack', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::DB_OPERATION_NAME, 'rollback');
                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, mixed $statement, ?Throwable $exception) {
                self::$depth['rollBack'] = max(0, (self::$depth['rollBack'] ?? 1) - 1);
                if (self::$depth['rollBack'] === 0) {
                    self::end($exception);
                }
            }
        );

        hook(
            \Doctrine\DBAL\Driver\Statement::class,
            'execute',
            pre: static function (\Doctrine\DBAL\Driver\Statement $statement, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $tracker): void {
                self::$depth['execute'] = (self::$depth['execute'] ?? 0) + 1;
                if (self::$depth['execute'] > 1) {
                    return;
                }
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'Doctrine::execute', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::DB_OPERATION_NAME, 'execute');
                if ($ctx = $tracker->getSpanContextForStatement($statement)) {
                    $builder->addLink($ctx);
                }
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (\Doctrine\DBAL\Driver\Statement $statement, array $params, ?ResultInterface $result, ?Throwable $exception) {
                self::$depth['execute'] = max(0, (self::$depth['execute'] ?? 1) - 1);
                if (self::$depth['execute'] === 0) {
                    self::end($exception);
                }
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
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno);
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
