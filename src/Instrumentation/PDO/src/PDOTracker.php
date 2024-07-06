<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\PDO;

use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\SemConv\TraceAttributes;
use PDO;
use PDOStatement;
use WeakMap;
use WeakReference;

final class PDOTracker
{
    /**
     * @var WeakMap<PDO, iterable<non-empty-string, bool|int|float|string|array|null>>
     */
    private WeakMap $pdoToAttributesMap;
    /**
     * @var WeakMap<PDOStatement, WeakReference<PDO>>
     */
    private WeakMap $statementMapToPdoMap;
    private WeakMap $preparedStatementToSpanMap;

    public function __construct()
    {
        /** @psalm-suppress PropertyTypeCoercion */
        $this->pdoToAttributesMap = new WeakMap();
        /** @psalm-suppress PropertyTypeCoercion */
        $this->statementMapToPdoMap = new WeakMap();
        $this->preparedStatementToSpanMap = new WeakMap();
    }

    /**
     * Maps a prepared statement to the PDO instance and the span context it was created in
     */
    public function trackStatement(PDOStatement $statement, PDO $pdo, SpanContextInterface $spanContext): void
    {
        $this->statementMapToPdoMap[$statement] = WeakReference::create($pdo);
        $this->preparedStatementToSpanMap[$statement] = WeakReference::create($spanContext);
    }

    /**
     * Maps a statement back to the connection attributes.
     *
     * @param PDOStatement $statement
     * @return iterable<non-empty-string, bool|int|float|string|array|null>
     */
    public function trackedAttributesForStatement(PDOStatement $statement): iterable
    {
        $pdo = ($this->statementMapToPdoMap[$statement] ?? null)?->get();
        if ($pdo === null) {
            return [];
        }

        return $this->pdoToAttributesMap[$pdo] ?? [];
    }

    /**
     * @param PDO $pdo
     * @param string $dsn
     * @return iterable<non-empty-string, bool|int|float|string|array|null>
     */
    public function trackPdoAttributes(PDO $pdo, string $dsn): iterable
    {
        $attributes = self::extractAttributesFromDSN($dsn);

        try {
            /** @var string $dbSystem */
            $dbSystem = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            /** @psalm-suppress InvalidArrayAssignment */
            $attributes[TraceAttributes::DB_SYSTEM] = self::mapDriverNameToAttribute($dbSystem);
        } catch (\Error) {
            // if we catched an exception, the driver is likely not supporting the operation, default to "other"
            /** @psalm-suppress PossiblyInvalidArrayAssignment */
            $attributes[TraceAttributes::DB_SYSTEM] = 'other_sql';
        }

        $this->pdoToAttributesMap[$pdo] = $attributes;

        return $attributes;
    }

    /**
     * @param PDO $pdo
     * @return iterable<non-empty-string, bool|int|float|string|array|null>
     */
    public function trackedAttributesForPdo(PDO $pdo): iterable
    {
        return $this->pdoToAttributesMap[$pdo] ?? [];
    }

    public function getSpanForPreparedStatement(PDOStatement $statement): ?SpanContextInterface
    {
        return ($this->preparedStatementToSpanMap[$statement] ?? null)?->get();
    }

    /**
     * Mapping to known values
     *
     * @link https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/trace/semantic_conventions/database.md#notes-and-well-known-identifiers-for-dbsystem
     * @param string|null $driverName
     * @return string
     */
    private static function mapDriverNameToAttribute(?string $driverName): string
    {
        return match ($driverName) {
            'mysql' => 'mysql',
            'pgsql' => 'postgresql',
            'sqlite' => 'sqlite',
            'sqlsrv' => 'mssql',
            'oci' => 'oracle',
            'ibm' => 'db2',
            default => 'other_sql',
        };
    }

    /**
     * Extracts attributes from a DSN string
     *
     * @param string $dsn
     * @return iterable<non-empty-string, bool|int|float|string|array|null>
     */
    private static function extractAttributesFromDSN(string $dsn): iterable
    {
        $attributes = [];
        if (str_starts_with($dsn, 'sqlite::memory:')) {
            $attributes[TraceAttributes::DB_SYSTEM] = 'sqlite';
            $attributes[TraceAttributes::DB_NAME] = 'memory';

            return $attributes;
        } elseif (str_starts_with($dsn, 'sqlite:')) {
            $attributes[TraceAttributes::DB_SYSTEM] = 'sqlite';
            $attributes[TraceAttributes::DB_NAME] = substr($dsn, 7);

            return $attributes;
        } elseif (str_starts_with($dsn, 'sqlite')) {
            $attributes[TraceAttributes::DB_SYSTEM] = 'sqlite';
            $attributes[TraceAttributes::DB_NAME] = $dsn;

            return $attributes;
        }

        if (preg_match('/user=([^;]*)/', $dsn, $matches)) {
            $user = $matches[1];
            if ($user !== '') {
                $attributes[TraceAttributes::DB_USER] = $user;
            }
        }
        if (preg_match('/host=([^;]*)/', $dsn, $matches)) {
            $host = $matches[1];
            if ($host !== '') {
                $attributes[TraceAttributes::NET_PEER_NAME] = $host;
                $attributes[TraceAttributes::SERVER_ADDRESS] = $host;
            }
        }
        if (preg_match('/dbname=([^;]*)/', $dsn, $matches)) {
            $dbname = $matches[1];
            if ($dbname !== '') {
                $attributes[TraceAttributes::DB_NAME] = $dbname;
            }
        }

        return $attributes;
    }
}
