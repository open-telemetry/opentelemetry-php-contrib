<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\PDO;

require_once '/home/grzegorz/work/opentelemetry-php/src/SDK/Util/AttributeTrackerById.php';
require_once '/home/grzegorz/work/opentelemetry-php/src/SDK/Util/AttributeTrackerByObject.php';
require_once '/home/grzegorz/work/opentelemetry-php/src/SDK/Metrics/Util/TimerTrackerById.php';
require_once '/home/grzegorz/work/opentelemetry-php/src/SDK/Metrics/Util/TimerTrackerByObject.php';

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\Version;
use PDO;
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
            Version::VERSION_1_30_0->url(),
        );
        $pdoTracker = new PDOTracker();
        $timersTracker = new \OpenTelemetry\SDK\Metrics\Util\TimerTrackerByObject();
        $attributesTracker = new \OpenTelemetry\SDK\Util\AttributeTrackerByObject();
        hook(
            PDO::class,
            '__construct',
            pre: static function (PDO $pdo, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $attributesTracker) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'PDO::__construct', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
                $connectionAttributes = [
                    TraceAttributes::SERVER_ADDRESS => $params[0] ?? 'unknown',
                    TraceAttributes::SERVER_PORT => $params[0] ?? null
                ];
                if ($class === PDO::class) {
                    //@todo split params[0] into host + port, replace deprecated trace attribute
                    $builder->setAttributes($connectionAttributes);

                    $attributesTracker->set($pdo, $connectionAttributes);
                }
                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));

                $instrumentation->meter()
                    ->createUpDownCounter('db.client.connection.count', '1')
                    ->add(1, $connectionAttributes, $parent);
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

        hook(PDO::class, '__destruct', post:function ($pdo) use ($instrumentation, $attributesTracker) {
            $parent = Context::getCurrent();

            $connectionAttributes = $attributesTracker->get($pdo);
            $instrumentation->meter()
                ->createUpDownCounter('db.client.connection.count', '1')
                ->add(-1, $connectionAttributes, $parent);
        });

        hook(
            PDO::class,
            'query',
            pre: static function (PDO $pdo, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($pdoTracker, $instrumentation, $timersTracker, $attributesTracker) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'PDO::query', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);

                if ($class === PDO::class) {
                    $encodedQuery = mb_convert_encoding($params[0] ?? 'undefined', 'UTF-8');
                    $builder->setAttribute(TraceAttributes::DB_QUERY_TEXT, $encodedQuery);
                    $attributesTracker->append($pdo, TraceAttributes::DB_QUERY_TEXT, $encodedQuery);
                }
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                $attributes = $pdoTracker->trackedAttributesForPdo($pdo);
                $span->setAttributes($attributes);

                Context::storage()->attach($span->storeInContext($parent));

                $metricAttributes = $attributesTracker->get($pdo);
                self::createPendingOperationMetric($instrumentation, $metricAttributes, 1);
                $timersTracker->start($pdo);
            },
            post: static function (PDO $pdo, array $params, mixed $statement, ?Throwable $exception) use ($instrumentation, $attributesTracker, $timersTracker) {
                $duration = $timersTracker->durationMs($pdo);
                self::end($exception);

                $metricAttributes = $attributesTracker->get($pdo);
                self::createDurationMetric($instrumentation, $metricAttributes, $duration);
                self::createPendingOperationMetric($instrumentation, $metricAttributes, -1);
                if ($statement instanceof PDOStatement && $statement->rowCount()) {
                    self::createReturnedRowsMetric($instrumentation, $metricAttributes, $statement->rowCount());
                }
            }
        );

        hook(
            PDO::class,
            'exec',
            pre: static function (PDO $pdo, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($pdoTracker, $instrumentation, $attributesTracker, $timersTracker) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'PDO::exec', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT);
                if ($class === PDO::class) {
                    $encodedQuery = mb_convert_encoding($params[0] ?? 'undefined', 'UTF-8');
                    $builder->setAttribute(TraceAttributes::DB_QUERY_TEXT, $encodedQuery);
                    $attributesTracker->append($pdo, TraceAttributes::DB_QUERY_TEXT, $encodedQuery);
                }
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                $attributes = $pdoTracker->trackedAttributesForPdo($pdo);
                $span->setAttributes($attributes);

                Context::storage()->attach($span->storeInContext($parent));

                $metricAttributes = $attributesTracker->get($pdo);
                self::createPendingOperationMetric($instrumentation, $metricAttributes, 1);

                $timersTracker->start($pdo);
            },
            post: static function (PDO $pdo, array $params, mixed $affectedRows, ?Throwable $exception) use ($instrumentation, $attributesTracker, $timersTracker) {
                $duration = $timersTracker->durationMs($pdo);
                self::end($exception);

                $metricAttributes = $attributesTracker->get($pdo);
                self::createDurationMetric($instrumentation, $metricAttributes, $duration);
                self::createPendingOperationMetric($instrumentation, $metricAttributes, -1);
                if (!empty($affectedRows)) {
                    self::createReturnedRowsMetric($instrumentation, $metricAttributes, $affectedRows);
                }
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
                    $builder->setAttribute(TraceAttributes::DB_QUERY_TEXT, mb_convert_encoding($params[0] ?? 'undefined', 'UTF-8'));
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
                    $attributes[TraceAttributes::DB_QUERY_TEXT] = $statement->queryString;
                }
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'PDOStatement::fetchAll', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttributes($attributes);
                if ($spanContext = $pdoTracker->getSpanForPreparedStatement($statement)) {
                    $builder->addLink($spanContext);
                }
                $span = $builder->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (PDOStatement $statement, array $params, mixed $retval, ?Throwable $exception) {
                self::end($exception);
            }
        );

        hook(
            PDOStatement::class,
            'execute',
            pre: static function (PDOStatement $statement, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($pdoTracker, $instrumentation, $timersTracker, $attributesTracker) {
                $attributes = $pdoTracker->trackedAttributesForStatement($statement);

                if (self::isDistributeStatementToLinkedSpansEnabled()) {
                    $attributes[TraceAttributes::DB_QUERY_TEXT] = $statement->queryString;
                }

                $attributesTracker->append($statement, TraceAttributes::DB_QUERY_TEXT, $statement->queryString);

                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, 'PDOStatement::execute', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttributes($attributes);
                if ($spanContext = $pdoTracker->getSpanForPreparedStatement($statement)) {
                    $builder->addLink($spanContext);
                }
                $span = $builder->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));

                $metricAttributes = $attributesTracker->get($statement);
                self::createPendingOperationMetric($instrumentation, $metricAttributes, 1);
                $timersTracker->start($statement);
            },
            post: static function (PDOStatement $statement, array $params, mixed $retval, ?Throwable $exception) use ($instrumentation, $attributesTracker, $timersTracker) {
                $duration = $timersTracker->durationMs($statement);
                self::end($exception);

                $metricAttributes = $attributesTracker->get($statement);
                self::createDurationMetric($instrumentation, $metricAttributes, $duration);
                self::createPendingOperationMetric($instrumentation, $metricAttributes, -1);
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
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
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
