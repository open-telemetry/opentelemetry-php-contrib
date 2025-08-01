<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\PostgreSql;

use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;

use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\Version;
use PgSql\Connection;
use PgSql\Lob;

/**
 * @phan-file-suppress PhanParamTooFewUnpack
 */
class PostgreSqlInstrumentation
{
    use LogsMessagesTrait;

    public const NAME = 'postgresql';
    public static function register(): void
    {

        // https://opentelemetry.io/docs/specs/semconv/database/postgresql/
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.postgresql',
            null,
            Version::VERSION_1_30_0->url(),
        );

        $tracker = new PgSqlTracker();

        hook(
            null,
            'pg_connect',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::connectPreHook('pg_connect', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::connectPostHook($instrumentation, $tracker, ...$args);
            }
        );
        hook(
            null,
            'pg_pconnect',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::connectPreHook('pg_pconnect', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::connectPostHook($instrumentation, $tracker, ...$args);
            }
        );
        hook(
            null,
            'pg_convert',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_convert', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::tableOperationsPostHook($instrumentation, $tracker, true, null, ...$args);
            }
        );

        hook(
            null,
            'pg_copy_from',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_copy_from', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::tableOperationsPostHook($instrumentation, $tracker, false, null, ...$args);
            }
        );

        hook(
            null,
            'pg_copy_to',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_copy_to', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::tableOperationsPostHook($instrumentation, $tracker, false, null, ...$args);
            }
        );

        hook(
            null,
            'pg_delete',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_delete', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::tableOperationsPostHook($instrumentation, $tracker, false, 'DELETE', ...$args);
            }
        );

        hook(
            null,
            'pg_prepare',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_prepare', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::preparePostHook($instrumentation, $tracker, false, ...$args);
            }
        );

        hook(
            null,
            'pg_execute',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_execute', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::executePostHook($instrumentation, $tracker, false, ...$args);
            }
        );

        hook(
            null,
            'pg_query',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_query', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::queryPostHook($instrumentation, $tracker, ...$args);
            }
        );

        hook(
            null,
            'pg_select',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_select', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::selectPostHook($instrumentation, $tracker, ...$args);
            }
        );

        hook(
            null,
            'pg_send_prepare',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_send_prepare', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::preparePostHook($instrumentation, $tracker, true, ...$args);
            }
        );

        hook(
            null,
            'pg_send_execute',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_send_execute', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::executePostHook($instrumentation, $tracker, true, ...$args);
            }
        );
        hook(
            null,
            'pg_send_query',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_send_query', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::sendQueryPostHook($instrumentation, $tracker, ...$args);
            }
        );
        hook(
            null,
            'pg_send_query_params',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_send_query_params', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::sendQueryParamsPostHook($instrumentation, $tracker, ...$args);
            }
        );

        hook(
            null,
            'pg_get_result',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_get_result', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::getResultPostHook($instrumentation, $tracker, ...$args);
            }
        );

        hook(
            null,
            'pg_lo_open',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_lo_open', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::loOpenPostHook($instrumentation, $tracker, ...$args);
            }
        );

        hook(
            null,
            'pg_lo_write',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_lo_write', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::loWritePostHook($instrumentation, $tracker, ...$args);
            }
        );

        hook(
            null,
            'pg_lo_read',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_lo_read', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::loReadPostHook($instrumentation, $tracker, ...$args);
            }
        );

        hook(
            null,
            'pg_lo_read_all',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_lo_read_all', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::loReadAllPostHook($instrumentation, $tracker, ...$args);
            }
        );

        hook(
            null,
            'pg_lo_unlink',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_lo_unlink', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::loUnlinkPostHook($instrumentation, $tracker, ...$args);
            }
        );

        hook(
            null,
            'pg_lo_import',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_lo_import', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::loImportExportPostHook($instrumentation, $tracker, 'IMPORT', ...$args);
            }
        );

        hook(
            null,
            'pg_lo_export',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_lo_export', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::loImportExportPostHook($instrumentation, $tracker, 'EXPORT', ...$args);
            }
        );
    }

    /** @param non-empty-string $spanName */
    private static function connectPreHook(string $spanName, CachedInstrumentation $instrumentation, PgSqlTracker $tracker, $obj, array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno): void
    {
        $attributes = PgSqlTracker::parseAttributesFromConnectionString($params[0]);
        self::startSpan($spanName, $instrumentation, $class, $function, $filename, $lineno, $attributes);
    }

    private static function connectPostHook(CachedInstrumentation $instrumentation, PgSqlTracker $tracker, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {
        if ($retVal instanceof Connection) {
            $tracker->storeConnectionAttributes($retVal, $params[0]);
        }
        self::endSpan([], $exception, $retVal == false ? 'Connection error' : null);
    }

    /** @param non-empty-string $spanName */
    private static function basicPreHook(string $spanName, CachedInstrumentation $instrumentation, PgSqlTracker $tracker, $obj, array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno): void
    {
        self::startSpan($spanName, $instrumentation, $class, $function, $filename, $lineno, []);
    }

    private static function tableOperationsPostHook(CachedInstrumentation $instrumentation, PgSqlTracker $tracker, bool $dropIfNoError, ?string $operationName, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {
        $connection = $params[0];
        $attributes = null;
        if ($connection instanceof Connection) {
            $attributes = $tracker->getConnectionAttributes($connection);
            $attributes[TraceAttributes::DB_COLLECTION_NAME] = mb_convert_encoding($params[1], 'UTF-8');
            if ($operationName) {
                $attributes[TraceAttributes::DB_OPERATION_NAME] = $operationName;
            }
        }

        $errorStatus = $retVal == false ? pg_last_error($params[0]) : null;
        if ($dropIfNoError && $errorStatus === null && $exception === null) {
            self::dropSpan();

            return;
        }
        self::endSpan($attributes, $exception, $errorStatus);
    }

    private static function preparePostHook(CachedInstrumentation $instrumentation, PgSqlTracker $tracker, bool $async, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {
        $attributes = $tracker->getConnectionAttributes($params[0]);

        $attributes[TraceAttributes::DB_QUERY_TEXT] = mb_convert_encoding($params[2], 'UTF-8');
        $attributes[TraceAttributes::DB_OPERATION_NAME] = self::extractQueryCommand($params[2]);

        $errorStatus = $retVal == false ? pg_last_error($params[0]) : null;

        if ($retVal != false) {
            $tracker->addConnectionStatement($params[0], $params[1], $params[2]);

            if ($async) {
                $tracker->addAsyncLinkForConnection($params[0], Span::getCurrent()->getContext());
            }

        }

        self::endSpan($attributes, $exception, $errorStatus);
    }

    private static function sendQueryParamsPostHook(CachedInstrumentation $instrumentation, PgSqlTracker $tracker, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {
        $attributes = $tracker->getConnectionAttributes($params[0]);

        $attributes[TraceAttributes::DB_QUERY_TEXT] = mb_convert_encoding($params[1], 'UTF-8');
        $attributes[TraceAttributes::DB_OPERATION_NAME] = self::extractQueryCommand($params[1]);

        $errorStatus = $retVal == false ? pg_last_error($params[0]) : null;

        if ($retVal != false) {
            $tracker->addAsyncLinkForConnection($params[0], Span::getCurrent()->getContext());
        }

        self::endSpan($attributes, $exception, $errorStatus);
    }

    private static function getResultPostHook(CachedInstrumentation $instrumentation, PgSqlTracker $tracker, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {
        $attributes = $tracker->getConnectionAttributes($params[0]);

        if ($retVal !== false) {
            if ($linkedContext = $tracker->getAsyncLinkForConnection($params[0])) {
                Span::getCurrent()->addLink($linkedContext);
            }
            self::endSpan($attributes, $exception, null);
        } else {
            // pg_get_result() returns false when there are no more pending results.
            // This is normal and expected behavior — it is designed for polling.
            // A false return value simply means there are no results currently available.
            // There’s no point in creating a span that won’t be linked to any operation.
            self::dropSpan();
        }

    }

    private static function executePostHook(CachedInstrumentation $instrumentation, PgSqlTracker $tracker, bool $async, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {
        $attributes = $tracker->getConnectionAttributes($params[0]);

        $query = $tracker->getStatementQuery($params[0], $params[1]);
        if ($query !== null) {
            $attributes[TraceAttributes::DB_QUERY_TEXT] = mb_convert_encoding($query, 'UTF-8');
            $attributes[TraceAttributes::DB_OPERATION_NAME] = self::extractQueryCommand($query);
        }

        if ($retVal != false) {
            if ($async) {
                $tracker->addAsyncLinkForConnection($params[0], Span::getCurrent()->getContext());
            }
        }

        $errorStatus = $retVal == false ? pg_last_error($params[0]) : null;
        self::endSpan($attributes, $exception, $errorStatus);
    }

    private static function sendQueryPostHook(CachedInstrumentation $instrumentation, PgSqlTracker $tracker, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {
        $attributes = $tracker->getConnectionAttributes($params[0]);

        $queries = PgSqlTracker::splitQueries($params[1]);
        $queriesCount = count($queries);
        for ($i = 0; $i < $queriesCount; $i++) {
            $tracker->addAsyncLinkForConnection($params[0], Span::getCurrent()->getContext());
        }

        $attributes[TraceAttributes::DB_QUERY_TEXT] = mb_convert_encoding($params[1], 'UTF-8');
        $attributes[TraceAttributes::DB_OPERATION_NAME] = self::extractQueryCommand($params[1]);

        $errorStatus = $retVal == false ? pg_last_error($params[0]) : null;
        self::endSpan($attributes, $exception, $errorStatus);
    }

    private static function queryPostHook(CachedInstrumentation $instrumentation, PgSqlTracker $tracker, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {
        $attributes = $tracker->getConnectionAttributes($params[0]);

        $attributes[TraceAttributes::DB_QUERY_TEXT] = mb_convert_encoding($params[1], 'UTF-8');
        $attributes[TraceAttributes::DB_OPERATION_NAME] = self::extractQueryCommand($params[1]);

        $errorStatus = $retVal == false ? pg_last_error($params[0]) : null;
        self::endSpan($attributes, $exception, $errorStatus);
    }

    private static function selectPostHook(CachedInstrumentation $instrumentation, PgSqlTracker $tracker, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {
        $attributes = $tracker->getConnectionAttributes($params[0]);

        if ($retVal != false) {
            $table = $params[1];
            $conditions = $params[2];
            $query = null;

            if (empty($conditions)) {
                if (PHP_VERSION_ID >= 80400) {
                    $query = "SELECT * FROM {$table}";
                } else {
                    $query = null;
                }
            } else {
                $where = implode(' AND ', array_map(
                    fn (string $k, $v) => null === $v ? $k . ' IS NULL' : $k . " = '$v'",
                    array_keys($conditions),
                    $conditions
                ));
                $query = "SELECT * FROM {$table} WHERE {$where}";
            }

            if ($query) {
                $attributes[TraceAttributes::DB_QUERY_TEXT] = mb_convert_encoding($query, 'UTF-8');
            }
            $attributes[TraceAttributes::DB_COLLECTION_NAME] = mb_convert_encoding($table, 'UTF-8');
            $attributes[TraceAttributes::DB_OPERATION_NAME] = 'SELECT';
        }

        $errorStatus = $retVal == false ? pg_last_error($params[0]) : null;
        self::endSpan($attributes, $exception, $errorStatus);
    }

    private static function loOpenPostHook(CachedInstrumentation $instrumentation, PgSqlTracker $tracker, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {
        $attributes = $tracker->getConnectionAttributes($params[0]);
        $attributes[TraceAttributes::DB_OPERATION_NAME] = 'OPEN';

        if ($retVal instanceof Lob) {
            $tracker->trackConnectionFromLob($params[0], $retVal);
        }

        $errorStatus = $retVal == false ? pg_last_error($params[0]) : null;
        self::endSpan($attributes, $exception, $errorStatus);
    }

    private static function loWritePostHook(CachedInstrumentation $instrumentation, PgSqlTracker $tracker, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {
        $attributes = [];
        $lob = $params[0];
        if ($lob instanceof Lob) {
            if ($connection = $tracker->getConnectionFromLob($lob)) {
                $attributes = $tracker->getConnectionAttributes($connection);
            }
            if ($retVal !== false) {
                $attributes['db.postgres.bytes_written'] = $retVal;
            }
        }

        $attributes[TraceAttributes::DB_OPERATION_NAME] = 'WRITE';

        $errorStatus = $retVal == false ? pg_last_error($params[0]) : null;
        self::endSpan($attributes, $exception, $errorStatus);
    }

    private static function loReadPostHook(CachedInstrumentation $instrumentation, PgSqlTracker $tracker, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {
        $attributes = [];
        $lob = $params[0];
        if ($lob instanceof Lob) {
            if ($connection = $tracker->getConnectionFromLob($lob)) {
                $attributes = $tracker->getConnectionAttributes($connection);
            }
        }
        $attributes[TraceAttributes::DB_OPERATION_NAME] = 'READ';
        $errorStatus = $retVal == false ? pg_last_error($params[0]) : null;
        self::endSpan($attributes, $exception, $errorStatus);
    }

    private static function loReadAllPostHook(CachedInstrumentation $instrumentation, PgSqlTracker $tracker, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {
        $attributes = [];

        $lob = $params[0];
        if ($lob instanceof Lob) {
            if ($connection = $tracker->getConnectionFromLob($lob)) {
                $attributes = $tracker->getConnectionAttributes($connection);
            }
            if ($retVal !== false) {
                $attributes['db.postgres.bytes_read'] = $retVal;
            }
        }
        $attributes[TraceAttributes::DB_OPERATION_NAME] = 'READ';

        $errorStatus = $retVal == false ? pg_last_error($params[0]) : null;
        self::endSpan($attributes, $exception, $errorStatus);
    }

    private static function loUnlinkPostHook(CachedInstrumentation $instrumentation, PgSqlTracker $tracker, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {
        $attributes = $tracker->getConnectionAttributes($params[0]);
        $attributes[TraceAttributes::DB_OPERATION_NAME] = 'DELETE';

        $errorStatus = $retVal == false ? pg_last_error($params[0]) : null;
        self::endSpan($attributes, $exception, $errorStatus);
    }

    private static function loImportExportPostHook(CachedInstrumentation $instrumentation, PgSqlTracker $tracker, string $operation, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {
        $attributes = $tracker->getConnectionAttributes($params[0]);
        $attributes[TraceAttributes::DB_OPERATION_NAME] = $operation;

        $errorStatus = $retVal == false ? pg_last_error($params[0]) : null;
        self::endSpan($attributes, $exception, $errorStatus);
    }

    /** @param non-empty-string $spanName */
    private static function startSpan(string $spanName, CachedInstrumentation $instrumentation, ?string $class, ?string $function, ?string $filename, ?int $lineno, iterable $attributes) : SpanInterface
    {
        $parent = Context::getCurrent();
        $builder = $instrumentation->tracer()
            ->spanBuilder($spanName)
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, ($class ? $class . '::' : '') . $function)
            ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
            ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
            ->setAttribute(TraceAttributes::DB_SYSTEM_NAME, 'postgresql')
            // @phan-suppress-next-line PhanDeprecatedClassConstant
            ->setAttribute(TraceAttributes::DB_SYSTEM, 'postgresql')
            ->setAttributes($attributes);

        $span = $builder->startSpan();
        $context = $span->storeInContext($parent);

        Context::storage()->attach($context);

        return $span;
    }

    private static function endSpan(?iterable $attributes, ?\Throwable $exception, ?string $errorStatus)
    {
        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }
        $scope->detach();
        $span = Span::fromContext($scope->context());

        if ($attributes) {
            $span->setAttributes($attributes);
        }

        if ($errorStatus !== null) {
            $span->setAttribute(TraceAttributes::EXCEPTION_MESSAGE, $errorStatus);
            $span->setStatus(StatusCode::STATUS_ERROR, $errorStatus);
        }

        if ($exception) {
            $span->recordException($exception);
            $span->setAttribute(TraceAttributes::EXCEPTION_TYPE, $exception::class);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        }

        $span->end();
    }

    private static function dropSpan()
    {
        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }
        $scope->detach();
    }

    private static function extractQueryCommand($query) : ?string
    {
        $query = preg_replace("/\r\n|\n\r|\r/", "\n", $query);
        /** @psalm-suppress PossiblyInvalidArgument */
        if (preg_match('/^\s*(?:--[^\n]*\n|\/\*[\s\S]*?\*\/\s*)*([a-zA-Z_][a-zA-Z0-9_]*)/i', $query, $matches)) {
            return strtoupper($matches[1]);
        }

        return null;
    }

}
