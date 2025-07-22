<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\PostgreSql;

use PgSql\Connection;
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

/**
 * @phan-file-suppress PhanParamTooFewUnpack
 */
class PostgreSqlInstrumentation
{
    use LogsMessagesTrait;

    public const NAME = 'postgresql';
    public static function register(): void
    {

//TODO Large objet - track by PgLob instance
//pg_query_params, pg_select

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
                self::tableOperationsPostHook($instrumentation, $tracker, true,  ...$args);
            }
        );



        hook(
            null,
            'pg_copy_from',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_copy_from', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::tableOperationsPostHook($instrumentation, $tracker, false, ...$args);
            }
        );

        hook(
            null,
            'pg_copy_to',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_copy_to', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::tableOperationsPostHook($instrumentation, $tracker, false, ...$args);
            }
        );

        hook(
            null,
            'pg_delete',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_delete', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::tableOperationsPostHook($instrumentation, $tracker, false, ...$args);
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
                self::executePostHook($instrumentation, false, $tracker, ...$args);
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
            'pg_send_prepare',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_send_prepare', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::preparePostHook($instrumentation, $tracker, true,  ...$args);
            }
        );

        hook(
            null,
            'pg_send_execute',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::basicPreHook('pg_send_execute', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::executePostHook($instrumentation, $tracker, true,  ...$args);
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
        self::endSpan([], $exception, $retVal == false ? "Connection error" : null);
    }

    /** @param non-empty-string $spanName */
    private static function basicPreHook(string $spanName, CachedInstrumentation $instrumentation, PgSqlTracker $tracker, $obj, array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno): void
    {
        self::startSpan($spanName, $instrumentation, $class, $function, $filename, $lineno, []);
    }

    private static function basicPostHook(CachedInstrumentation $instrumentation, PgSqlTracker $tracker, ?array $attributes, bool $dropIfNoError, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {
        $errorStatus = $retVal == false ? pg_last_error($params[0]) : null;
        if ($dropIfNoError && $errorStatus === null && $exception === null) {
            self::dropSpan();
            return;
        }
        self::endSpan($attributes, $exception, $errorStatus);
    }

    private static function tableOperationsPostHook(CachedInstrumentation $instrumentation, PgSqlTracker $tracker, bool $dropIfNoError, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {
        $connection = $params[0];
        $attributes = null;
        if ($connection instanceof Connection) {
            $attributes = $tracker->getConnectionAttributes($connection);
            $attributes[TraceAttributes::DB_NAMESPACE] = mb_convert_encoding($params[1], 'UTF-8');
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

        $errorStatus = $retVal == false ? pg_last_error($params[0]) : null;

        if ($retVal !== false) {
            if ($linkedContext = $tracker->getAsyncLinkForConnection($params[0])) {
                Span::getCurrent()->addLink($linkedContext);
            }
        }

        self::endSpan($attributes, $exception, $errorStatus);
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

    /** @param non-empty-string $spanName */
    private static function startSpan(string $spanName, CachedInstrumentation $instrumentation, ?string $class, ?string $function, ?string $filename, ?int $lineno, iterable $attributes) : SpanInterface
    {
        $parent = Context::getCurrent();
        $builder = $instrumentation->tracer()
            ->spanBuilder($spanName)
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
            ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
            ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
            ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
            ->setAttribute(TraceAttributes::DB_SYSTEM_NAME, 'postgresql')
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
