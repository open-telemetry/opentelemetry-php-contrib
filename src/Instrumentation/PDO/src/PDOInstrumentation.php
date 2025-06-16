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
use OpenTelemetry\SDK\Metrics\Util\TimerTrackerByObject;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\Version;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

class PDOInstrumentation
{
    public const NAME = 'pdo';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.pdo',
            null,
            Version::VERSION_1_32_0->url(),
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

                    // guard against PDO::connect returning a string
                    if ($result instanceof PDO) {
                        $attributes = $pdoTracker->trackPdoAttributes($result, $dsn);
                        $span->setAttributes($attributes);
                    }

                    self::end($exception);
                    
                    $attributes = $pdoTracker->get($result);
                    $parent = Context::getCurrent();

                    $instrumentation->meter()
                        ->createUpDownCounter('db.client.connection.count', '1')
                        ->add(1, $attributes, $parent);

                    $pdoTracker->trackPdoInstancesDestruction(
                        $object,
                        function ($pdoInstance) use ($instrumentation, $pdoTracker) {
                            $parent = Context::getCurrent();

                            $attributes = $pdoTracker->get($pdoInstance);
                            $instrumentation->meter()
                                ->createUpDownCounter('db.client.connection.count', '1')
                                ->add(-1, $attributes, $parent);
                        }
                    );
                }
            );
        }

        hook(
            PDO::class,
            '__construct',
            pre: static function (PDO $pdo, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $pdoTracker) {
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

                $attributes = $pdoTracker->get($pdo);
                $parent = Context::getCurrent();

                $instrumentation->meter()
                    ->createUpDownCounter('db.client.connection.count', '1')
                    ->add(1, $attributes, $parent);

                $pdoTracker->trackPdoInstancesDestruction(
                    $pdo,
                    function ($pdoInstance) use ($instrumentation, $pdoTracker) {
                        $parent = Context::getCurrent();

                        $attributes = $pdoTracker->get($pdoInstance);
                        $instrumentation->meter()
                            ->createUpDownCounter('db.client.connection.count', '1')
                            ->add(-1, $attributes, $parent);
                    }
                );
            }
        );

        hook(
            PDO::class,
            'query',
            pre: static function (PDO $pdo, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $pdoTracker, $timersTracker) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'PDO::query', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
                $encodedQuery = mb_convert_encoding($params[0] ?? 'undefined', 'UTF-8');
                if ($class === PDO::class) {
                    $builder->setAttribute(TraceAttributes::DB_QUERY_TEXT, $encodedQuery);
                }
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                $attributes = $pdoTracker->append($pdo, TraceAttributes::DB_QUERY_TEXT, $encodedQuery);
                $span->setAttributes($attributes);

                Context::storage()->attach($span->storeInContext($parent));

                self::createPendingOperationMetric($instrumentation, $attributes, 1);
                $timersTracker->start($pdo);
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
                self::createPendingOperationMetric($instrumentation, $attributes, -1);
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
                $encodedQuery = mb_convert_encoding($params[0] ?? 'undefined', 'UTF-8');
                if ($class === PDO::class) {
                    $builder->setAttribute(TraceAttributes::DB_QUERY_TEXT, $encodedQuery);
                }
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                $attributes = $pdoTracker->append($pdo, TraceAttributes::DB_QUERY_TEXT, $encodedQuery);
                $span->setAttributes($attributes);

                Context::storage()->attach($span->storeInContext($parent));

                self::createPendingOperationMetric($instrumentation, $attributes, 1);

                $timersTracker->start($pdo);
            },
            post: static function (PDO $pdo, array $params, mixed $affectedRows, ?Throwable $exception) use ($instrumentation, $pdoTracker, $timersTracker) {
                $duration = $timersTracker->durationMs($pdo);
                self::end($exception, $affectedRows === false ? $pdo->errorInfo() : []);

                $attributes = $pdoTracker->get($pdo);
                self::createDurationMetric($instrumentation, $attributes, $duration);
                self::createPendingOperationMetric($instrumentation, $attributes, -1);
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
                $encodedQuery = mb_convert_encoding($params[0] ?? 'undefined', 'UTF-8');
                if ($class === PDO::class) {
                    $builder->setAttribute(TraceAttributes::DB_QUERY_TEXT, $encodedQuery);
                }
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                $attributes = $pdoTracker->append($pdo, TraceAttributes::DB_QUERY_TEXT, $encodedQuery);
                $span->setAttributes($attributes);

                Context::storage()->attach($span->storeInContext($parent));

                self::createPendingOperationMetric($instrumentation, $attributes, 1);
                
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
                self::createPendingOperationMetric($instrumentation, $attributes, -1);
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

                $attributes = $pdoTracker->get($pdo);
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
                    $attributes[TraceAttributes::DB_QUERY_TEXT] = $statement->queryString;
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
            pre: static function (PDOStatement $statement, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $pdoTracker, $timersTracker) {
                $attributes = $pdoTracker->trackedAttributesForStatement($statement);

                if (self::isDistributeStatementToLinkedSpansEnabled()) {
                    /** @psalm-suppress InvalidArrayAssignment */
                    $attributes[TraceAttributes::DB_QUERY_TEXT] = $statement->queryString;
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

                self::createPendingOperationMetric($instrumentation, $attributes, 1);
                $timersTracker->start($statement);
            },
            post: static function (PDOStatement $statement, array $params, mixed $retval, ?Throwable $exception) use ($instrumentation, $pdoTracker, $timersTracker) {
                $duration = $timersTracker->durationMs($statement);
                self::end($exception, $retval === false ? $statement->errorInfo() : []);

                $attributes = $pdoTracker->trackedAttributesForStatement($statement);
                self::createDurationMetric($instrumentation, $attributes, $duration);
                self::createPendingOperationMetric($instrumentation, $attributes, -1);
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

    private static function end(?Throwable $exception, array $errorInfo = []): void
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

        } elseif (!empty($errorInfo[2]) && $errorMessage = $errorInfo[2]) {
            $span->addEvent('exception', [
                'exception.type' => PDOException::class,
                'exception.message' => $errorMessage,
                // @todo try to add stacktrace?
            ]);

            $span->setStatus(StatusCode::STATUS_ERROR, $errorMessage);
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
        array $attributes,
        int $value,
    ): void {
        $parent = Context::getCurrent();
        $instrumentation->meter()
            ->createUpDownCounter('db.client.connection.pending_requests', '1')
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
}
