<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Str;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;

class QueryWatcher extends Watcher
{
    public function __construct(
        private CachedInstrumentation $instrumentation,
    ) {
    }

    /** @psalm-suppress UndefinedInterfaceMethod */
    public function register(Application $app): void
    {
        /** @phan-suppress-next-line PhanTypeArraySuspicious */
        $app['events']->listen(QueryExecuted::class, [$this, 'recordQuery']);
    }

    /**
     * Record a query.
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function recordQuery(QueryExecuted $query): void
    {
        $nowInNs = (int) (microtime(true) * 1E9);

        $operationName = Str::upper(Str::before($query->sql, ' '));
        if (! in_array($operationName, ['SELECT', 'INSERT', 'UPDATE', 'DELETE'])) {
            $operationName = null;
        }

        /** @psalm-suppress ArgumentTypeCoercion */
        $span = $this->instrumentation->tracer()->spanBuilder('sql ' . $operationName)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setStartTimestamp($this->calculateQueryStartTime($nowInNs, $query->time))
            ->startSpan();

        $attributes = [
            DbAttributes::DB_SYSTEM_NAME => $this->getDbSystemName($query->connection->getDriverName()),
            DbAttributes::DB_NAMESPACE => $query->connection->getDatabaseName(),
            DbAttributes::DB_OPERATION_NAME => $operationName,
            DbAttributes::DB_QUERY_TEXT => $query->sql,

            ServerAttributes::SERVER_ADDRESS => $query->connection->getConfig('host'),
            ServerAttributes::SERVER_PORT => $query->connection->getConfig('port'),
        ];

        /** @psalm-suppress PossiblyInvalidArgument */
        $span->setAttributes($attributes);
        $span->end($nowInNs);
    }

    private function calculateQueryStartTime(int $nowInNs, float $queryTimeMs): int
    {
        return (int) ($nowInNs - ($queryTimeMs * 1E6));
    }

    /**
     * Map Laravel's database driver names to OpenTelemetry's db.system values.
     *
     * @see Illuminate\Database\DatabaseManager::supportedDrivers() for the list of supported drivers.
     */
    private function getDbSystemName(string $driverName): string
    {
        return match ($driverName) {
            'mysql' => DbAttributes::DB_SYSTEM_NAME_VALUE_MYSQL,
            'pgsql' => DbAttributes::DB_SYSTEM_NAME_VALUE_POSTGRESQL,
            'mariadb' => DbAttributes::DB_SYSTEM_NAME_VALUE_MARIADB,
            'sqlsrv' => DbAttributes::DB_SYSTEM_NAME_VALUE_MICROSOFT_SQL_SERVER,
            default => $driverName,
        };
    }
}
