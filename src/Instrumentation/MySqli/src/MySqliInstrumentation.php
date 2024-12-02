<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\MySqli;

use mysqli;
use mysqli_stmt;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;

use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;

class MySqliInstrumentation
{
    use LogsMessagesTrait;

    public const NAME = 'mysqli';

    private const MYSQLI_CONNECT_ARG_OFFSET = 0;
    private const MYSQLI_REAL_CONNECT_ARG_OFFSET = 1; // The mysqli_real_connect function in procedural mode requires a mysqli object as its first argument. The remaining arguments are consistent with those used in other connection methods, such as connect or __construct

    public static function register(): void
    {

        // https://opentelemetry.io/docs/specs/semconv/database/mysql/
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.mysqli',
            null,
            'https://opentelemetry.io/schemas/1.27.1'
        );

        $tracker = new MySqliTracker();

        hook(
            null,
            'mysqli_connect',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::constructPreHook('mysqli_connect', self::MYSQLI_CONNECT_ARG_OFFSET, $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::constructPostHook(self::MYSQLI_CONNECT_ARG_OFFSET, $instrumentation, $tracker, ...$args);
            }
        );
        hook(
            mysqli::class,
            '__construct',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::constructPreHook('mysqli::__construct', self::MYSQLI_CONNECT_ARG_OFFSET, $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::constructPostHook(self::MYSQLI_CONNECT_ARG_OFFSET, $instrumentation, $tracker, ...$args);
            }
        );
        hook(
            mysqli::class,
            'connect',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::constructPreHook('mysqli::connect', self::MYSQLI_CONNECT_ARG_OFFSET, $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::constructPostHook(self::MYSQLI_CONNECT_ARG_OFFSET, $instrumentation, $tracker, ...$args);
            }
        );

        hook(
            mysqli::class,
            'real_connect',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::constructPreHook('mysqli::real_connect', self::MYSQLI_CONNECT_ARG_OFFSET, $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::constructPostHook(self::MYSQLI_CONNECT_ARG_OFFSET, $instrumentation, $tracker, ...$args);
            }
        );
        hook(
            null,
            'mysqli_real_connect',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::constructPreHook('mysqli_real_connect', self::MYSQLI_REAL_CONNECT_ARG_OFFSET, $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::constructPostHook(self::MYSQLI_REAL_CONNECT_ARG_OFFSET, $instrumentation, $tracker, ...$args);
            }
        );

        hook(
            null,
            'mysqli_query',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::queryPreHook('mysqli_query', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::queryPostHook($instrumentation, $tracker, ...$args);
            }
        );
        hook(
            mysqli::class,
            'query',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::queryPreHook('mysqli::query', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::queryPostHook($instrumentation, $tracker, ...$args);
            }
        );

        hook(
            null,
            'mysqli_real_query',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::queryPreHook('mysqli_real_query', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::queryPostHook($instrumentation, $tracker, ...$args);
            }
        );
        hook(
            mysqli::class,
            'real_query',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::queryPreHook('mysqli::real_query', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::queryPostHook($instrumentation, $tracker, ...$args);
            }
        );

        hook(
            null,
            'mysqli_execute_query',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::queryPreHook('mysqli_execute_query', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::queryPostHook($instrumentation, $tracker, ...$args);
            }
        );
        hook(
            mysqli::class,
            'execute_query',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::queryPreHook('mysqli::execute_query', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::queryPostHook($instrumentation, $tracker, ...$args);
            }
        );

        hook(
            null,
            'mysqli_multi_query',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::queryPreHook('mysqli_multi_query', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::multiQueryPostHook($instrumentation, $tracker, ...$args);
            }
        );
        hook(
            mysqli::class,
            'multi_query',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::queryPreHook('mysqli::multi_query', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::multiQueryPostHook($instrumentation, $tracker, ...$args);
            }
        );

        hook(
            null,
            'mysqli_next_result',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::nextResultPreHook('mysqli_next_result', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::nextResultPostHook($instrumentation, $tracker, ...$args);
            }
        );

        hook(
            mysqli::class,
            'next_result',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::nextResultPreHook('mysqli::next_result', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::nextResultPostHook($instrumentation, $tracker, ...$args);
            }
        );

        hook(
            null,
            'mysqli_change_user',
            pre: null,
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::changeUserPostHook($instrumentation, $tracker, ...$args);
            }
        );
        hook(
            mysqli::class,
            'change_user',
            pre: null,
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::changeUserPostHook($instrumentation, $tracker, ...$args);
            }
        );

        hook(
            null,
            'mysqli_select_db',
            pre: null,
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::selectDbPostHook($instrumentation, $tracker, ...$args);
            }
        );
        hook(
            mysqli::class,
            'select_db',
            pre: null,
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::selectDbPostHook($instrumentation, $tracker, ...$args);
            }
        );

        hook(
            null,
            'mysqli_prepare',
            pre: null,
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::preparePostHook($instrumentation, $tracker, ...$args);
            }
        );

        hook(
            mysqli::class,
            'prepare',
            pre: null,
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::preparePostHook($instrumentation, $tracker, ...$args);
            }
        );

        // Statement hooks

        hook(
            mysqli::class,
            'stmt_init',
            pre: null,
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::stmtInitPostHook($instrumentation, $tracker, ...$args);
            }
        );
        hook(
            null,
            'mysqli_stmt_init',
            pre: null,
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::stmtInitPostHook($instrumentation, $tracker, ...$args);
            }
        );

        hook(
            mysqli_stmt::class,
            'prepare',
            pre: null,
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::stmtPreparePostHook($instrumentation, $tracker, ...$args);
            }
        );
        hook(
            null,
            'mysqli_stmt_prepare',
            pre: null,
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::stmtPreparePostHook($instrumentation, $tracker, ...$args);
            }
        );

        hook(
            mysqli_stmt::class,
            '__construct',
            pre: null,
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::stmtConstructPostHook($instrumentation, $tracker, ...$args);
            }
        );

        hook(
            mysqli_stmt::class,
            'execute',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::stmtExecutePreHook('mysqli_stmt::execute', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::stmtExecutePostHook($instrumentation, $tracker, ...$args);
            }
        );
        hook(
            null,
            'mysqli_stmt_execute',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::stmtExecutePreHook('mysqli_stmt_execute', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::stmtExecutePostHook($instrumentation, $tracker, ...$args);
            }
        );

        hook(
            mysqli_stmt::class,
            'next_result',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::stmtNextResultPreHook('mysqli_stmt::next_result', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::stmtNextResultPostHook($instrumentation, $tracker, ...$args);
            }
        );
        hook(
            null,
            'mysqli_stmt_next_result',
            pre: static function (...$args) use ($instrumentation, $tracker) {
                self::stmtNextResultPreHook('mysqli_stmt_next_result', $instrumentation, $tracker, ...$args);
            },
            post: static function (...$args) use ($instrumentation, $tracker) {
                self::stmtNextResultPostHook($instrumentation, $tracker, ...$args);
            }
        );

        //TODO test to https://www.php.net/manual/en/mysqli.begin-transaction.php
    }

    private static function constructPreHook(string $spanName, int $paramsOffset, CachedInstrumentation $instrumentation, MySqliTracker $tracker, $obj, array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno): void
    {
        $attributes[TraceAttributes::SERVER_ADDRESS] = $params[$paramsOffset + 0] ?? get_cfg_var('mysqli.default_host');
        $attributes[TraceAttributes::SERVER_PORT] = $params[$paramsOffset + 4] ?? get_cfg_var('mysqli.default_port');
        $attributes[TraceAttributes::DB_USER] = $params[$paramsOffset + 1] ?? get_cfg_var('mysqli.default_user');
        $attributes[TraceAttributes::DB_NAMESPACE] = $params[$paramsOffset + 3] ?? null;
        $attributes[TraceAttributes::DB_SYSTEM] =  'mysql';

        self::startSpan($spanName, $instrumentation, $class, $function, $filename, $lineno, $attributes);
    }

    private static function constructPostHook(int $paramsOffset, CachedInstrumentation $instrumentation, MySqliTracker $tracker, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {

        $mysqliObject = null;

        if ($obj && $retVal !== false) { // even if constructor fails, we will get and temporary object which will be assigned (or not) alter in user code
            $mysqliObject = $obj;
        } elseif ($retVal instanceof mysqli) { // procedural mode
            $mySqliObject = $retVal;
        } elseif ($paramsOffset == self::MYSQLI_REAL_CONNECT_ARG_OFFSET && $retVal !== false && $params[0] instanceof mysqli) { // real_connect procedural mode
            $mysqliObject = $params[0];
        }

        if ($mysqliObject) {
            $tracker->storeMySqliAttributes($mysqliObject, $params[$paramsOffset + 0] ?? null, $params[$paramsOffset + 1] ?? null, $params[$paramsOffset + 3] ?? null, $params[$paramsOffset + 4] ?? null, null);
        }

        self::endSpan([], $exception, ($retVal === false && !$exception) ? mysqli_connect_error() : null);

    }

    private static function queryPreHook(string $spanName, CachedInstrumentation $instrumentation, MySqliTracker $tracker, $obj, array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno): void
    {
        self::startSpan($spanName, $instrumentation, $class, $function, $filename, $lineno, []);
    }

    private static function queryPostHook(CachedInstrumentation $instrumentation, MySqliTracker $tracker, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {

        $mysqli = $obj ? $obj : $params[0];
        $query = $obj ? $params[0] : $params[1];

        $attributes = $tracker->getMySqliAttributes($mysqli);
        $attributes[TraceAttributes::DB_STATEMENT] = mb_convert_encoding($query, 'UTF-8');
        $attributes[TraceAttributes::DB_OPERATION_NAME] = self::extractQueryCommand($query);

        if ($retVal === false || $exception) {
            //TODO use constant from comment  after sem-conv update
            $attributes[/*TraceAttributes::DB_RESPONSE_STATUS_CODE*/ 'db.response.status_code'] =  $mysqli->errno;
        }

        $errorStatus = ($retVal === false && !$exception) ? $mysqli->error : null;
        self::endSpan($attributes, $exception, $errorStatus);

    }

    private static function multiQueryPostHook(CachedInstrumentation $instrumentation, MySqliTracker $tracker, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {

        $mysqli = $obj ? $obj : $params[0];
        $query = $obj ? $params[0] : $params[1];

        $attributes = $tracker->getMySqliAttributes($mysqli);

        $tracker->storeMySqliMultiQuery($mysqli, $query);
        if ($currentQuery = $tracker->getNextMySqliMultiQuery($mysqli)) {
            $attributes[TraceAttributes::DB_STATEMENT] = mb_convert_encoding($currentQuery, 'UTF-8');
            $attributes[TraceAttributes::DB_OPERATION_NAME] = self::extractQueryCommand($currentQuery);
        }

        if ($retVal === false || $exception) {
            //TODO use constant from comment  after sem-conv update
            $attributes[/*TraceAttributes::DB_RESPONSE_STATUS_CODE*/ 'db.response.status_code'] =  $mysqli->errno;
        } else {
            $tracker->trackMySqliSpan($mysqli, Span::getCurrent()->getContext());
        }

        $errorStatus = ($retVal === false && !$exception) ? $mysqli->error : null;
        self::endSpan($attributes, $exception, $errorStatus);

    }

    private static function nextResultPreHook(string $spanName, CachedInstrumentation $instrumentation, MySqliTracker $tracker, $obj, array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno): void
    {
        $span = self::startSpan($spanName, $instrumentation, $class, $function, $filename, $lineno, []);
        $mysqli = $obj ? $obj : $params[0];
        if ($mysqli instanceof mysqli && ($spanContext = $tracker->getMySqliSpan($mysqli))) {
            $span->addLink($spanContext);
        }

    }

    private static function nextResultPostHook(CachedInstrumentation $instrumentation, MySqliTracker $tracker, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {

        $mysqli = $obj ? $obj : $params[0];

        $errorStatus = ($retVal === false && !$exception) ? (strlen($mysqli->error) > 0 ? $mysqli->error : null) : null;

        $attributes = $tracker->getMySqliAttributes($mysqli);

        $currentQuery = $tracker->getNextMySqliMultiQuery($mysqli);

        // it was just a call to check if there is a pending query
        if ($currentQuery === null || ($retVal === false && !$errorStatus && !$exception)) {
            self::logDebug('nextResultPostHook span dropped', ['exception' => $exception, 'obj' => $obj, 'retVal' => $retVal, 'params' => $params, 'currentQuery' => $currentQuery]);
            self::dropSpan();

            return;
        }

        if ($currentQuery) {
            $attributes[TraceAttributes::DB_STATEMENT] = mb_convert_encoding($currentQuery, 'UTF-8');
            $attributes[TraceAttributes::DB_OPERATION_NAME] = self::extractQueryCommand($currentQuery);
        }

        if ($retVal === false || $exception) {
            //TODO use constant from comment  after sem-conv update
            $attributes[/*TraceAttributes::DB_RESPONSE_STATUS_CODE*/ 'db.response.status_code'] =  $mysqli->errno;
        }

        self::endSpan($attributes, $exception, $errorStatus);
    }

    private static function changeUserPostHook(CachedInstrumentation $instrumentation, MySqliTracker $tracker, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {

        if ($retVal != true) {
            return; //TODO create error span?
        }

        $mysqli = $obj ? $obj : $params[0];

        $tracker->addMySqliAttribute($mysqli, TraceAttributes::DB_USER, $params[$obj ? 0 : 1]);
        if (($database = $params[$obj ? 2 : 3] ?? null) !== null) {
            $tracker->addMySqliAttribute($mysqli, TraceAttributes::DB_NAMESPACE, $database);
        }

    }

    private static function selectDbPostHook(CachedInstrumentation $instrumentation, MySqliTracker $tracker, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {

        if ($retVal != true) {
            return; //TODO create error span?
        }
        $tracker->addMySqliAttribute($obj ? $obj : $params[0], TraceAttributes::DB_NAMESPACE, $params[$obj ? 0 : 1]);
    }

    private static function preparePostHook(CachedInstrumentation $instrumentation, MySqliTracker $tracker, $obj, array $params, mixed $stmtRetVal, ?\Throwable $exception)
    {

        if ($exception || !$stmtRetVal instanceof mysqli_stmt) {
            self::logDebug('mysqli::prepare failed', ['exception' => $exception, 'obj' => $obj, 'retVal' => $stmtRetVal, 'params' => $params]);

            return;
        }

        $mysqli = $obj ? $obj : $params[0];
        $query = $params[$obj ? 0 : 1];

        $tracker->trackMySqliFromStatement($mysqli, $stmtRetVal);

        $tracker->addStatementAttribute($stmtRetVal, TraceAttributes::DB_STATEMENT, mb_convert_encoding($query, 'UTF-8'));
        $tracker->addStatementAttribute($stmtRetVal, TraceAttributes::DB_OPERATION_NAME, self::extractQueryCommand($query));

    }

    private static function stmtInitPostHook(CachedInstrumentation $instrumentation, MySqliTracker $tracker, $mySqliObj, array $params, mixed $retVal, ?\Throwable $exception)
    {
        if ($retVal !== false) {
            $tracker->trackMySqliFromStatement($mySqliObj ? $mySqliObj : $params[0], $retVal);
        }
    }

    private static function stmtPreparePostHook(CachedInstrumentation $instrumentation, MySqliTracker $tracker, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {
        // There is no need to create a span for prepare. It is a partial operation that is not executed on the database, so we do not need to measure its execution time.
        if ($retVal != true) {
            self::logDebug('mysqli::prepare failed', ['exception' => $exception, 'obj' => $obj, 'retVal' => $retVal, 'params' => $params]);

            return;
        }

        $query = $obj ? $params[0] : $params[1];
        $tracker->addStatementAttribute($obj ? $obj : $params[0], TraceAttributes::DB_STATEMENT, mb_convert_encoding($query, 'UTF-8'));
        $tracker->addStatementAttribute($obj ? $obj : $params[0], TraceAttributes::DB_OPERATION_NAME, self::extractQueryCommand($query));
    }

    private static function stmtConstructPostHook(CachedInstrumentation $instrumentation, MySqliTracker $tracker, $stmt, array $params, mixed $retVal, ?\Throwable $exception)
    {

        if ($exception) {
            self::logDebug('stmt::__construct failed', ['exception' => $exception, 'stmt' => $stmt, 'retVal' => $retVal, 'params' => $params]);

            return;
        }

        $tracker->trackMySqliFromStatement($params[0], $stmt);

        if ($params[1] ?? null) {
            $tracker->addStatementAttribute($stmt, TraceAttributes::DB_STATEMENT, mb_convert_encoding($params[1], 'UTF-8'));
            $tracker->addStatementAttribute($stmt, TraceAttributes::DB_OPERATION_NAME, self::extractQueryCommand($params[1]));
        }
    }

    private static function stmtExecutePreHook(string $spanName, CachedInstrumentation $instrumentation, MySqliTracker $tracker, $obj, array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno): void
    {
        self::startSpan($spanName, $instrumentation, $class, $function, $filename, $lineno, []);
    }

    private static function stmtExecutePostHook(CachedInstrumentation $instrumentation, MySqliTracker $tracker, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {
        $stmt = $obj ? $obj : $params[0];
        $attributes = array_merge($tracker->getMySqliAttributesFromStatement($stmt), $tracker->getStatementAttributes($stmt));

        if ($retVal === false || $exception) {
            //TODO use constant from comment  after sem-conv update
            $attributes[/*TraceAttributes::DB_RESPONSE_STATUS_CODE*/ 'db.response.status_code'] =  $stmt->errno;
        }

        $errorStatus = ($retVal === false && !$exception) ? $stmt->error : null;

        $tracker->trackStatementSpan($stmt, Span::getCurrent()->getContext());

        self::endSpan($attributes, $exception, $errorStatus);

    }

    private static function stmtNextResultPreHook(string $spanName, CachedInstrumentation $instrumentation, MySqliTracker $tracker, $obj, array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno): void
    {
        $span = self::startSpan($spanName, $instrumentation, $class, $function, $filename, $lineno, []);

        $stmt = $obj ? $obj : $params[0];
        if ($spanContext = $tracker->getStatementSpan($stmt)) {
            $span->addLink($spanContext);
        }

    }

    private static function stmtNextResultPostHook(CachedInstrumentation $instrumentation, MySqliTracker $tracker, $obj, array $params, mixed $retVal, ?\Throwable $exception)
    {
        $stmt = $obj ? $obj : $params[0];
        $attributes = array_merge($tracker->getMySqliAttributesFromStatement($stmt), $tracker->getStatementAttributes($stmt));

        if ($retVal === false && $stmt->errno == 0 && !$exception) {
            // it was just a call to check if there is a pending result
            self::logDebug('stmtNextResultPostHook span dropped', ['exception' => $exception, 'obj' => $obj, 'retVal' => $retVal, 'params' => $params]);

            self::dropSpan();

            return;
        }

        if ($retVal === false || $exception) {
            //TODO use constant from comment  after sem-conv update
            $attributes[/*TraceAttributes::DB_RESPONSE_STATUS_CODE*/ 'db.response.status_code'] =  $stmt->errno;
        }

        $errorStatus = ($retVal === false && !$exception) ? $stmt->error : null;

        self::endSpan($attributes, $exception, $errorStatus);
    }

    private static function startSpan(string $spanName, CachedInstrumentation $instrumentation, ?string $class, ?string $function, ?string $filename, ?int $lineno, iterable $attributes) : SpanInterface
    {
        $parent = Context::getCurrent();
        $builder = $instrumentation->tracer()
            ->spanBuilder($spanName)
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
            ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
            ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
            ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
            ->setAttributes($attributes);

        $span = $builder->startSpan();
        $context = $span->storeInContext($parent);

        Context::storage()->attach($context);

        return $span;
    }

    private static function endSpan(array $attributes, ?\Throwable $exception, ?string $errorStatus)
    {
        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }
        $scope->detach();
        $span = Span::fromContext($scope->context());

        $span->setAttributes($attributes);

        if ($errorStatus !== null) {
            $span->setAttribute(TraceAttributes::EXCEPTION_MESSAGE, $errorStatus);
            $span->setStatus(StatusCode::STATUS_ERROR, $errorStatus);
        }

        if ($exception) {
            $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
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
        if (preg_match('/^\s*(?:--[^\n]*\n|\/\*[\s\S]*?\*\/\s*)*([a-zA-Z_][a-zA-Z0-9_]*)/i', $query, $matches)) {
            return strtoupper($matches[1]);
        }

        return null;
    }

}