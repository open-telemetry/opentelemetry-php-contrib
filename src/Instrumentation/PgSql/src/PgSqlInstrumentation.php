<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\PgSql;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\Version;
use PgSql\Connection;
use PgSql\Result;
use Throwable;

class PgSqlInstrumentation
{
    public const NAME = 'pgsql';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.pgsql',
            null,
            Version::VERSION_1_32_0->url(),
        );

        $tracker = new PgSqlTracker();

        hook(
            null,
            'pg_connect',
            /**
             * @param array{0: string, 1?: int} $params
             * @param non-empty-string $function
             */
            pre: static function (mixed $obj, array $params, ?string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                self::beginSpan($instrumentation, $function, $filename, $lineno);
            },
            /**
             * @param array{0: string, 1?: int} $params
             */
            post: static function (mixed $obj, array $params, Connection|false $returnValue, ?Throwable $exception) use ($tracker) {
                $connection = null;
                $attributes = [];

                if ($returnValue !== false) {
                    $connection = $returnValue;
                    $port = pg_port($connection);

                    $attributes = [
                        TraceAttributes::DB_NAMESPACE => pg_dbname($connection),
                        TraceAttributes::DB_SYSTEM_NAME => 'postgresql',
                        TraceAttributes::SERVER_ADDRESS => pg_host($connection),
                        TraceAttributes::SERVER_PORT => $port ? (int) $port : null,
                    ];

                    $tracker->trackConnectionAttributes($connection, $attributes);
                }

                self::endSpan($connection, $returnValue, $attributes);
            }
        );

        hook(
            null,
            'pg_query',
            /**
             * @param array{Connection, string}|array{string} $params
             * @param non-empty-string $function
             */
            pre: static function (mixed $obj, array $params, ?string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $tracker) {
                if (count($params) < 2) {
                    array_unshift($params, null);
                }

                /** @var ?Connection $connection */
                list($connection) = $params;
                $attributes = $connection ? $tracker->trackedAttributesForConnection($connection) : [];
                self::beginSpan($instrumentation, $function, $filename, $lineno, $attributes);
            },
            /**
             * @param array{Connection, string}|array{string} $params
             */
            post: static function (mixed $obj, array $params, Result|false $returnValue, ?Throwable $exception) {
                if (count($params) < 2) {
                    array_unshift($params, null);
                }

                /** @var ?Connection $connection */
                list($connection) = $params;
                $attributes = $returnValue !== false ? [TraceAttributes::DB_RESPONSE_RETURNED_ROWS => pg_num_rows($returnValue)] : [];
                self::endSpan($connection, $returnValue, $attributes);
            }
        );

        hook(
            null,
            'pg_query_params',
            /**
             * @param array{Connection, string, array}|array{string, array} $params
             * @param non-empty-string $function
             */
            pre: static function (mixed $obj, array $params, ?string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $tracker) {
                if (count($params) < 3) {
                    array_unshift($params, null);
                }

                /**
                 * @var ?Connection $connection
                 * @var string $query
                 * @var array $queryParams
                 */
                list($connection, $query, $queryParams) = $params;

                $attributes = $connection ? $tracker->trackedAttributesForConnection($connection) : [];
                $attributes[TraceAttributes::DB_QUERY_TEXT] = mb_convert_encoding($query, 'UTF-8');

                foreach ($queryParams as $i => $queryParam) {
                    $attributes[TraceAttributes::DB_QUERY_PARAMETER . ".$i"] = mb_convert_encoding((string) $queryParam, 'UTF-8');
                }

                self::beginSpan($instrumentation, $function, $filename, $lineno, $attributes);
            },
            /**
             * @param array{Connection, string, array}|array{string, array} $params
             */
            post: static function (mixed $obj, array $params, Result|false $returnValue, ?Throwable $exception) {
                if (count($params) < 3) {
                    array_unshift($params, null);
                }

                /** @var ?Connection $connection */
                list($connection) = $params;
                $attributes = $returnValue !== false ? [TraceAttributes::DB_RESPONSE_RETURNED_ROWS => pg_num_rows($returnValue)] : [];
                self::endSpan($connection, $returnValue, $attributes);
            }
        );
    }

    /**
     * @param non-empty-string $function
     * @param array<non-empty-string, bool|int|float|string|array|null> $attributes
     */
    private static function beginSpan(CachedInstrumentation $instrumentation, string $function, ?string $filename, ?int $lineno, array $attributes = []): void
    {
        $span = $instrumentation->tracer()
            ->spanBuilder($function)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
            ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
            ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
            ->setAttributes($attributes)
            ->startSpan();

        Context::storage()->attach($span->storeInContext(Context::getCurrent()));
    }

    /**
     * @param array<non-empty-string, bool|int|float|string|array|null> $attributes
     */
    private static function endSpan(?Connection $connection, Connection|Result|bool $returnValue, array $attributes = []): void
    {
        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }

        $scope->detach();

        $span = Span::fromContext($scope->context());
        $span->setAttributes($attributes);

        if ($returnValue === false) {
            $description = $connection ? pg_last_error($connection) : null;
            $span->setStatus(StatusCode::STATUS_ERROR, $description);
        }

        $span->end();
    }
}
