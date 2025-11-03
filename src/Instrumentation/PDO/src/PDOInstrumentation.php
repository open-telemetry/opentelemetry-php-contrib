<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\PDO;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Metrics\TimerTrackerByObject;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Version;
use PDO;
use PDOException;
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
        $timersTracker = new TimerTrackerByObject();

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
                ) use ($instrumentation, $pdoTracker) {
                    $scope = Context::storage()->scope();
                    if (!$scope) {
                        return;
                    }
                    $span = Span::fromContext($scope->context());

                    $dsn = $params[0] ?? '';
                    $attributes = [];
                    // guard against PDO::connect returning a string
                    if ($result instanceof PDO) {
                        $attributes = $pdoTracker->trackPdoAttributes($result, $dsn);
                        $span->setAttributes($attributes);
                    }

                    self::end($exception);
                    self::createConnectionMetrics($instrumentation, $attributes, $pdoTracker, $object);
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
            post: static function (PDO $pdo, array $params, mixed $statement, ?Throwable $exception) use ($instrumentation, $pdoTracker) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $span = Span::fromContext($scope->context());

                $dsn = $params[0] ?? '';

                $attributes = $pdoTracker->trackPdoAttributes($pdo, $dsn);
                $span->setAttributes($attributes);

                self::end($exception);
                self::createConnectionMetrics($instrumentation, $attributes, $pdoTracker, $pdo);
            }
        );

        hook(
            PDO::class,
            'query',
            pre: static function (PDO $pdo, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $pdoTracker, $timersTracker) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'PDO::query', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
                return self::preSendingQuery(
                    $instrumentation,
                    $builder,
                    $params[0],
                    $pdoTracker,
                    $pdo,
                    $timersTracker
                );
            },
            post: static function (PDO $pdo, array $params, mixed $statement, ?Throwable $exception) use ($instrumentation, $pdoTracker, $timersTracker) {
                $duration = $timersTracker->durationMs($pdo);
                // this happens ONLY when error mode is set to silent
                // this is an alternative to changes in the ::end method
                //                if ($statement === false && $exception === null) {
                //                    $exception = new class($pdo->errorInfo()) extends \PDOException {
                //                        // to workaround setting code that is not INT
                //                        public function __construct(array $errorInfo) {
                //                            $this->message = $errorInfo[2] ?? 'PDO error';
                //                            $this->code = $errorInfo[0] ?? 0;
                //                        }
                //                    };
                //                }

                self::end($exception, $statement === false ? $pdo->errorInfo() : []);

                $attributes = $pdoTracker->get($pdo);
                self::createDurationMetric($instrumentation, $attributes, $duration);
                self::createPendingOperationMetric(
                    $instrumentation,
                    'db.client.connection.pending_requests',
                    0,
                    $attributes
                );
                if ($statement instanceof PDOStatement && $statement->rowCount()) {
                    self::createReturnedRowsMetric($instrumentation, $attributes, $statement->rowCount());
                }
            }
        );

        hook(
            PDO::class,
            'exec',
            pre: static function (PDO $pdo, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $pdoTracker, $timersTracker) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'PDO::exec', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
                return self::preSendingQuery(
                    $instrumentation,
                    $builder,
                    $params[0],
                    $pdoTracker,
                    $pdo,
                    $timersTracker
                );
            },
            post: static function (PDO $pdo, array $params, mixed $affectedRows, ?Throwable $exception) use ($instrumentation, $pdoTracker, $timersTracker) {
                $duration = $timersTracker->durationMs($pdo);
                self::end($exception, $affectedRows === false ? $pdo->errorInfo() : []);

                $attributes = $pdoTracker->get($pdo);
                self::createDurationMetric($instrumentation, $attributes, $duration);
                self::createPendingOperationMetric(
                    $instrumentation,
                    'db.client.connection.pending_requests',
                    0,
                    $attributes
                );
                if (!empty($affectedRows)) {
                    self::createReturnedRowsMetric($instrumentation, $attributes, $affectedRows);
                }
            }
        );

        hook(
            PDO::class,
            'prepare',
            pre: static function (PDO $pdo, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $pdoTracker, $timersTracker) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'PDO::prepare', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
                $query = mb_convert_encoding($params[0] ?? self::UNDEFINED, 'UTF-8');
                if ($class === PDO::class) {
                    $builder->setAttribute(DbAttributes::DB_QUERY_TEXT, mb_convert_encoding($params[0] ?? 'undefined', 'UTF-8'));
                }
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                $attributes = $pdoTracker->append($pdo, TraceAttributes::DB_QUERY_TEXT, $query);
                $span->setAttributes($attributes);

                Context::storage()->attach($span->storeInContext($parent));

                self::createPendingOperationMetric(
                    $instrumentation,
                    'db.client.connection.pending_requests',
                    1,
                    $attributes
                );

                $timersTracker->start($pdo);
            },
            post: static function (PDO $pdo, array $params, mixed $statement, ?Throwable $exception) use ($instrumentation, $pdoTracker, $timersTracker) {
                $duration = $timersTracker->durationMs($pdo);
                if ($statement instanceof PDOStatement) {
                    $pdoTracker->trackStatement($statement, $pdo, Span::getCurrent()->getContext());
                }

                self::end($exception, $statement === false ? $pdo->errorInfo() : []);
                $attributes = $pdoTracker->get($pdo);
                self::createDurationMetric($instrumentation, $attributes, $duration);
                self::createPendingOperationMetric(
                    $instrumentation,
                    'db.client.connection.pending_requests',
                    0,
                    $attributes
                );
            }
        );

        hook(
            PDO::class,
            'beginTransaction',
            pre: static function (PDO $pdo, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $pdoTracker) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'PDO::beginTransaction', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                $attributes = $pdoTracker->get($pdo);
                $span->setAttributes($attributes);

                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (PDO $pdo, array $params, mixed $retval, ?Throwable $exception) {
                self::end($exception, $retval === false ? $pdo->errorInfo() : []);
            }
        );

        hook(
            PDO::class,
            'commit',
            pre: static function (PDO $pdo, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $pdoTracker) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'PDO::commit', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                $attributes = $pdoTracker->get($pdo);
                $span->setAttributes($attributes);

                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (PDO $pdo, array $params, mixed $retval, ?Throwable $exception) {
                self::end($exception, $retval === false ? $pdo->errorInfo() : []);
            }
        );

        hook(
            PDO::class,
            'rollBack',
            pre: static function (PDO $pdo, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $pdoTracker) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'PDO::rollBack', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                $attributes = $pdoTracker->trackedAttributesForPdo($pdo);
                $span->setAttributes($attributes);

                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (PDO $pdo, array $params, mixed $retval, ?Throwable $exception) {
                self::end($exception, $retval === false ? $pdo->errorInfo() : []);
            }
        );

        hook(
            PDOStatement::class,
            'fetchAll',
            pre: static function (PDOStatement $statement, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $pdoTracker) {
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
                self::end($exception, $retval === false ? $statement->errorInfo() : []);
            }
        );

        hook(
            PDOStatement::class,
            'execute',
            pre: static function (PDOStatement $statement, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $pdoTracker, $timersTracker) {
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

                self::createPendingOperationMetric(
                    $instrumentation,
                    'db.client.connection.pending_requests',
                    1,
                    $attributes
                );
                $timersTracker->start($statement);
            },
            post: static function (PDOStatement $statement, array $params, mixed $retval, ?Throwable $exception) use ($instrumentation, $pdoTracker, $timersTracker) {
                $duration = $timersTracker->durationMs($statement);
                self::end($exception, $retval === false ? $statement->errorInfo() : []);

                $attributes = $pdoTracker->trackedAttributesForStatement($statement);
                self::createDurationMetric($instrumentation, $attributes, $duration);
                self::createPendingOperationMetric(
                    $instrumentation,
                    'db.client.connection.pending_requests',
                    0,
                    $attributes
                );
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

    private static function end(Throwable|PDOException|null $exception, array $errorInfo = []): void
    {
        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }
        $scope->detach();
        $span = Span::fromContext($scope->context());

        // when silent mode is set to true, there are no exceptions, so we need to create one to record it using
        // a common way of creating exceptions.
        // The only problem is that PHP Exception code is int and PDOException code is string
        if ($exception === null && !empty($errorInfo[2])) {
            /** @noinspection CallableParameterUseCaseInTypeContextInspection */
            $exception = new class($errorInfo) extends \PDOException {
                // to workaround setting code that is not INT
                /**
                 * @noinspection MagicMethodsValidityInspection
                 * @noinspection PhpMissingParentConstructorInspection
                 */
                public function __construct(array $errorInfo) {
                    $this->message = $errorInfo[2] ?? 'PDO error';
                    $this->code = $errorInfo[0] ?? 0;
                }
            };
        }

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

        return get_cfg_var('otel.instrumentation.pdo.distribute_statement_to_linked_spans');
    }

    protected static function createPendingOperationMetric(
        CachedInstrumentation $instrumentation,
        string $metricName,
        int $value,
        array $attributes,
    ): void {
        $parent = Context::getCurrent();
        $instrumentation->meter()
            ->createUpDownCounter($metricName, '1')
            ->add($value, $attributes, $parent);
    }

    protected static function createReturnedRowsMetric(
        CachedInstrumentation $instrumentation,
        array $attributes,
        int $value,
    ): void {
        $parent = Context::getCurrent();
        $instrumentation->meter()
            ->createHistogram('db.client.response.returned_rows', '1')
            ->record($value, $attributes, $parent);
    }

    protected static function createDurationMetric(
        CachedInstrumentation $instrumentation,
        array $attributes,
        float $value,
    ): void {
        $parent = Context::getCurrent();
        $instrumentation->meter()
            ->createHistogram('db.client.operation.duration', 'ms')
            ->record($value, $attributes, $parent);
    }

    protected static function createConnectionMetrics(
        CachedInstrumentation $instrumentation,
        array $attributes,
        PDOTracker $pdoTracker,
        PDO $pdo
    ): void {
        self::createPendingOperationMetric(
            $instrumentation,
            'db.client.connection.count',
            1,
            $attributes
        );

        $pdoTracker->trackPdoInstancesDestruction(
            $pdo,
            function ($pdoInstance) use ($instrumentation, $pdoTracker) {
                $attributes = $pdoTracker->get($pdoInstance);

                self::createPendingOperationMetric(
                    $instrumentation,
                    'db.client.connection.count',
                    0,
                    $attributes
                );
            }
        );
    }

    public static function preSendingQuery(
        CachedInstrumentation $instrumentation,
        SpanBuilderInterface $builder,
        $query,
        PDOTracker $pdoTracker,
        PDO $pdo,
        TimerTrackerByObject $timersTracker
    ): array {
        $query = mb_convert_encoding($query ?? self::UNDEFINED, 'UTF-8');
        if (!is_string($query)) {
            $query = self::UNDEFINED;
        } else {
            $builder->setAttribute(DbAttributes::DB_QUERY_TEXT, $query);
        }
        $parent = Context::getCurrent();
        $span = $builder->startSpan();

        $attributes = $pdoTracker->append($pdo, DbAttributes::DB_QUERY_TEXT, $query);
        $span->setAttributes($attributes);

        Context::storage()->attach($span->storeInContext($parent));
        self::createPendingOperationMetric(
            $instrumentation,
            'db.client.connection.pending_requests',
            1,
            $attributes
        );

        $timersTracker->start($pdo);

        if (class_exists('OpenTelemetry\Contrib\SqlCommenter\SqlCommenter') && $query !== self::UNDEFINED) {
            if (array_key_exists(DbAttributes::DB_SYSTEM_NAME, $attributes)) {
                /** @psalm-suppress PossiblyInvalidCast */
                switch ((string)$attributes[DbAttributes::DB_SYSTEM_NAME]) {
                    case 'postgresql':
                    case 'mysql':
                        /**
                         * @psalm-suppress UndefinedClass
                         */
                        $commenter = \OpenTelemetry\Contrib\SqlCommenter\SqlCommenter::getInstance();
                        $query = $commenter->inject($query);
                        if ($commenter->isAttributeEnabled()) {
                            $span->setAttributes([
                                DbAttributes::DB_QUERY_TEXT => (string)$query,
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
    }
}
