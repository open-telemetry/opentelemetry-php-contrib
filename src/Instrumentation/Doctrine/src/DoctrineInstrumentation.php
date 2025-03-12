<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Doctrine;

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

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.doctrine');

        hook(
            \Doctrine\DBAL\Driver::class,
            'connect',
            pre: static function (\Doctrine\DBAL\Driver $driver, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'Doctrine\DBAL\Driver::connect', $function, $class, $filename, $lineno)
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->setAttribute(TraceAttributes::SERVER_ADDRESS, AttributesResolver::get(TraceAttributes::SERVER_ADDRESS, func_get_args()))
                ->setAttribute(TraceAttributes::SERVER_PORT, AttributesResolver::get(TraceAttributes::SERVER_PORT, func_get_args()))
                ->setAttribute(TraceAttributes::DB_SYSTEM, AttributesResolver::get(TraceAttributes::DB_SYSTEM, func_get_args()))
                ->setAttribute(TraceAttributes::DB_NAMESPACE, AttributesResolver::get(TraceAttributes::DB_NAMESPACE, func_get_args()));
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
                $builder->setAttribute(TraceAttributes::DB_QUERY_TEXT, AttributesResolver::get(TraceAttributes::DB_QUERY_TEXT, func_get_args()));
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
                    ->setAttribute(TraceAttributes::DB_QUERY_TEXT, AttributesResolver::get(TraceAttributes::DB_QUERY_TEXT, func_get_args()));
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
                    ->setAttribute(TraceAttributes::DB_QUERY_TEXT, AttributesResolver::get(TraceAttributes::DB_QUERY_TEXT, func_get_args()));
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
            'beginTransaction',
            pre: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'Doctrine\DBAL\Driver\Connection::beginTransaction', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
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
                $builder = self::makeBuilder($instrumentation, 'Doctrine\DBAL\Driver\Connection::commit', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
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
                $builder = self::makeBuilder($instrumentation, 'Doctrine\DBAL\Driver\Connection::rollBack', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, mixed $statement, ?Throwable $exception) {
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
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);
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
            $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        }

        $span->end();
    }
}
