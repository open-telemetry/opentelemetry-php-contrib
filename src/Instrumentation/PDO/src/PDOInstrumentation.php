<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\PDO;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Version;
use PDO;
use PDOStatement;
use Throwable;

/** @phan-file-suppress PhanUndeclaredClassMethod */
class PDOInstrumentation
{
    public const NAME = 'pdo';
    private const UNDEFINED = 'undefined';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.pdo',
            null,
            Version::VERSION_1_36_0->url(),
        );
        $pdoTracker = new PDOTracker();

        // Hook for the new PDO::connect static method
        if (method_exists(PDO::class, 'connect')) {
            hook(
                PDO::class,
                'connect',
                pre: static function (
                    $object,
                    array $params,
                    string $class,
                    string $function,
                    ?string $filename,
                    ?int $lineno,
                ) use ($instrumentation) {
                    /** @psalm-suppress ArgumentTypeCoercion */
                    $builder = self::makeBuilder($instrumentation, 'PDO::connect', $function, $class, $filename, $lineno)
                        ->setSpanKind(SpanKind::KIND_CLIENT);

                    $parent = Context::getCurrent();
                    $span = $builder->startSpan();
                    Context::storage()->attach($span->storeInContext($parent));
                },
                post: static function (
                    $object,
                    array $params,
                    $result,
                    ?Throwable $exception,
                ) use ($pdoTracker) {
                    $scope = Context::storage()->scope();
                    if (!$scope) {
                        return;
                    }
                    $span = Span::fromContext($scope->context());

                    $dsn = $params[0] ?? '';

                    // guard against PDO::connect returning a string
                    if ($result instanceof PDO) {
                        $attributes = $pdoTracker->trackPdoAttributes($result, $dsn);
                        $span->setAttributes($attributes);
                    }

                    self::end($exception);
                }
            );
        }

        hook(
            PDO::class,
            '__construct',
            pre: static function (PDO $pdo, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'PDO::__construct', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (PDO $pdo, array $params, mixed $statement, ?Throwable $exception) use ($pdoTracker) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $span = Span::fromContext($scope->context());

                $dsn = $params[0] ?? '';

                $attributes = $pdoTracker->trackPdoAttributes($pdo, $dsn);
                $span->setAttributes($attributes);

                self::end($exception);
            }
        );

        hook(
            PDO::class,
            'query',
            pre: static function (PDO $pdo, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($pdoTracker, $instrumentation) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'PDO::query', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
                $query = mb_convert_encoding($params[0] ?? self::UNDEFINED, 'UTF-8');
                if (!is_string($query)) {
                    $query = self::UNDEFINED;
                }
                if ($class === PDO::class) {
                    $builder->setAttribute(DbAttributes::DB_QUERY_TEXT, $query);
                }
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                $attributes = $pdoTracker->trackedAttributesForPdo($pdo);
                $span->setAttributes($attributes);

                Context::storage()->attach($span->storeInContext($parent));

                if (class_exists('OpenTelemetry\Contrib\SqlCommenter\SqlCommenter') && $query !== self::UNDEFINED) {
                    if (array_key_exists(DbAttributes::DB_SYSTEM_NAME, $attributes)) {
                        /** @psalm-suppress PossiblyInvalidCast */
                        switch ((string) $attributes[DbAttributes::DB_SYSTEM_NAME]) {
                            case 'postgresql':
                            case 'mysql':
                                /**
                                 * @psalm-suppress UndefinedClass
                                 */
                                $commenter = \OpenTelemetry\Contrib\SqlCommenter\SqlCommenter::getInstance();
                                $query = $commenter->inject($query);
                                if ($commenter->isAttributeEnabled()) {
                                    $span->setAttributes([
                                        DbAttributes::DB_QUERY_TEXT => (string) $query,
                                    ]);
                                }

                                return [
                                    0 => $query,
                                ];
                            default:
                                // Do nothing, not a database we want to propagate
                                break;
                        }
                    }
                }

                return [];
            },
            post: static function (PDO $pdo, array $params, mixed $statement, ?Throwable $exception) {
                self::end($exception);
            }
        );

        hook(
            PDO::class,
            'exec',
            pre: static function (PDO $pdo, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($pdoTracker, $instrumentation) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'PDO::exec', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
                $query = mb_convert_encoding($params[0] ?? self::UNDEFINED, 'UTF-8');
                if (!is_string($query)) {
                    $query = self::UNDEFINED;
                }
                if ($class === PDO::class) {
                    $builder->setAttribute(DbAttributes::DB_QUERY_TEXT, $query);
                }
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                $attributes = $pdoTracker->trackedAttributesForPdo($pdo);
                $span->setAttributes($attributes);

                Context::storage()->attach($span->storeInContext($parent));

                if (class_exists('OpenTelemetry\Contrib\SqlCommenter\SqlCommenter') && $query !== self::UNDEFINED) {
                    if (array_key_exists(DbAttributes::DB_SYSTEM_NAME, $attributes)) {
                        /** @psalm-suppress PossiblyInvalidCast */
                        switch ((string) $attributes[DbAttributes::DB_SYSTEM_NAME]) {
                            case 'postgresql':
                            case 'mysql':
                                /**
                                 * @psalm-suppress UndefinedClass
                                 */
                                $commenter = \OpenTelemetry\Contrib\SqlCommenter\SqlCommenter::getInstance();
                                $query = $commenter->inject($query);
                                if ($commenter->isAttributeEnabled()) {
                                    $span->setAttributes([
                                        DbAttributes::DB_QUERY_TEXT => (string) $query,
                                    ]);
                                }

                                return [
                                    0 => $query,
                                ];
                            default:
                                // Do nothing, not a database we want to propagate
                                break;
                        }
                    }
                }

                return [];
            },
            post: static function (PDO $pdo, array $params, mixed $statement, ?Throwable $exception) {
                self::end($exception);
            }
        );

        hook(
            PDO::class,
            'prepare',
            pre: static function (PDO $pdo, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($pdoTracker, $instrumentation) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'PDO::prepare', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
                if ($class === PDO::class) {
                    $builder->setAttribute(DbAttributes::DB_QUERY_TEXT, mb_convert_encoding($params[0] ?? 'undefined', 'UTF-8'));
                }
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                $attributes = $pdoTracker->trackedAttributesForPdo($pdo);
                $span->setAttributes($attributes);

                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (PDO $pdo, array $params, mixed $statement, ?Throwable $exception) use ($pdoTracker) {
                if ($statement instanceof PDOStatement) {
                    $pdoTracker->trackStatement($statement, $pdo, Span::getCurrent()->getContext());
                }

                self::end($exception);
            }
        );

        hook(
            PDO::class,
            'beginTransaction',
            pre: static function (PDO $pdo, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($pdoTracker, $instrumentation) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'PDO::beginTransaction', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                $attributes = $pdoTracker->trackedAttributesForPdo($pdo);
                $span->setAttributes($attributes);

                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (PDO $pdo, array $params, mixed $statement, ?Throwable $exception) {
                self::end($exception);
            }
        );

        hook(
            PDO::class,
            'commit',
            pre: static function (PDO $pdo, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($pdoTracker, $instrumentation) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'PDO::commit', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                $attributes = $pdoTracker->trackedAttributesForPdo($pdo);
                $span->setAttributes($attributes);

                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (PDO $pdo, array $params, mixed $statement, ?Throwable $exception) {
                self::end($exception);
            }
        );

        hook(
            PDO::class,
            'rollBack',
            pre: static function (PDO $pdo, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($pdoTracker, $instrumentation) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'PDO::rollBack', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                $attributes = $pdoTracker->trackedAttributesForPdo($pdo);
                $span->setAttributes($attributes);

                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (PDO $pdo, array $params, mixed $statement, ?Throwable $exception) {
                self::end($exception);
            }
        );

        hook(
            PDOStatement::class,
            'fetchAll',
            pre: static function (PDOStatement $statement, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($pdoTracker, $instrumentation) {
                $attributes = $pdoTracker->trackedAttributesForStatement($statement);
                if (self::isDistributeStatementToLinkedSpansEnabled()) {
                    /** @psalm-suppress InvalidArrayAssignment */
                    $attributes[DbAttributes::DB_QUERY_TEXT] = $statement->queryString;
                }
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'PDOStatement::fetchAll', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttributes($attributes);
                if ($spanContext = $pdoTracker->getSpanForPreparedStatement($statement)) {
                    $builder->addLink($spanContext);
                }
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (PDOStatement $statement, array $params, mixed $retval, ?Throwable $exception) {
                self::end($exception);
            }
        );

        hook(
            PDOStatement::class,
            'execute',
            pre: static function (PDOStatement $statement, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($pdoTracker, $instrumentation) {
                $attributes = $pdoTracker->trackedAttributesForStatement($statement);

                if (self::isDistributeStatementToLinkedSpansEnabled()) {
                    /** @psalm-suppress InvalidArrayAssignment */
                    $attributes[DbAttributes::DB_QUERY_TEXT] = $statement->queryString;
                }

                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'PDOStatement::execute', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttributes($attributes);
                if ($spanContext = $pdoTracker->getSpanForPreparedStatement($statement)) {
                    $builder->addLink($spanContext);
                }
                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (PDOStatement $statement, array $params, mixed $retval, ?Throwable $exception) {
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

    private static function isDistributeStatementToLinkedSpansEnabled(): bool
    {
        if (class_exists('OpenTelemetry\SDK\Common\Configuration\Configuration')) {
            return Configuration::getBoolean('OTEL_PHP_INSTRUMENTATION_PDO_DISTRIBUTE_STATEMENT_TO_LINKED_SPANS', false);
        }

        return filter_var(get_cfg_var('otel.instrumentation.pdo.distribute_statement_to_linked_spans'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
