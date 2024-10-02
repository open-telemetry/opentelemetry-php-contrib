<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Doctrine;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

use function OpenTelemetry\Instrumentation\hook;

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
                $builder = self::makeBuilder($instrumentation, 'Doctrine\DBAL\Driver::connect', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
                $builder
                    ->setAttribute(TraceAttributes::SERVER_ADDRESS, $params[0] ?? 'unknown')
                    ->setAttribute(TraceAttributes::SERVER_PORT, $params[0] ?? 'unknown')
                    ->setAttribute(TraceAttributes::DB_USER, $params[1] ?? 'unknown');
                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (\Doctrine\DBAL\Driver $driver, array $params, \Doctrine\DBAL\Driver\Connection $connection, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                self::end($exception);
            }
        );

        hook(
            \Doctrine\DBAL\Driver\Connection::class,
            'query',
            pre: static function (\Doctrine\DBAL\Driver\Connection $connection, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $builder = self::makeBuilder($instrumentation, 'Doctrine\DBAL\Driver\Connection::query', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
                $builder->setAttribute(TraceAttributes::DB_QUERY_TEXT, $params[0] ?? 'undefined');
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
                $builder = self::makeBuilder($instrumentation, 'Doctrine\DBAL\Driver\Connection::exec', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
                $builder->setAttribute(TraceAttributes::DB_QUERY_TEXT, $params[0] ?? 'undefined');
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
                $builder = self::makeBuilder($instrumentation, 'Doctrine\DBAL\Driver\Connection::prepare', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
                $builder->setAttribute(TraceAttributes::DB_QUERY_TEXT, $params[0] ?? 'undefined');
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
