<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\PDO;

use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\SemConv\TraceAttributes;
use PDO;
use PDOStatement;
use WeakMap;
use WeakReference;

/**
 * @phan-file-suppress PhanNonClassMethodCall,PhanTypeArraySuspicious
 */
final class PDOTracker
{
    /**
     * @var WeakMap<PDO, array<non-empty-string, bool|int|float|string|array|null>>
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
     * @return array<non-empty-string, bool|int|float|string|array|null>
     */
    public function trackedAttributesForStatement(PDOStatement $statement): array
    {
        $pdo = ($this->statementMapToPdoMap[$statement] ?? null)?->get();
        if ($pdo === null) {
            return [];
        }

        /** @psalm-var array<non-empty-string, bool|int|float|string|array|null> */
        return $this->pdoToAttributesMap[$pdo] ?? [];
    }

    /**
     * @param PDO $pdo
     * @param string $dsn
     * @return array<non-empty-string, bool|int|float|string|array|null>
     */
    public function trackPdoAttributes(PDO $pdo, string $dsn): array
    {
        $attributes = self::extractAttributesFromDSN($dsn);

        try {
            /** @var string $dbSystem */
            $dbSystem = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            /** @psalm-suppress InvalidArrayAssignment */
            $attributes[TraceAttributes::DB_SYSTEM_NAME] = self::mapDriverNameToAttribute($dbSystem);
        } catch (\Error) {
            // if we caught an exception, the driver is likely not supporting the operation, default to "other"
            /** @psalm-suppress PossiblyInvalidArrayAssignment */
            $attributes[TraceAttributes::DB_SYSTEM_NAME] = 'other_sql';
        }

        $this->pdoToAttributesMap[$pdo] = $attributes;

        return $attributes;
    }

    /**
     * @param PDO $pdo
     * @return array<non-empty-string, bool|int|float|string|array|null>
     */
    public function trackedAttributesForPdo(PDO $pdo): array
    {
        /** @psalm-var array<non-empty-string, bool|int|float|string|array|null> */
        return $this->pdoToAttributesMap[$pdo] ?? [];
    }

    public function getSpanForPreparedStatement(PDOStatement $statement): ?SpanContextInterface
    {
        if (!$this->preparedStatementToSpanMap->offsetExists($statement)) {
            return null;
        }

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
     * @return array<non-empty-string, bool|int|float|string|array|null>
     */
    private static function extractAttributesFromDSN(string $dsn): array
    {
        $attributes = [];
        if (str_starts_with($dsn, 'sqlite::memory:')) {
            $attributes[TraceAttributes::DB_SYSTEM_NAME] = 'sqlite';
            $attributes[TraceAttributes::DB_NAMESPACE] = 'memory';

            return $attributes;
        } elseif (str_starts_with($dsn, 'sqlite:')) {
            $attributes[TraceAttributes::DB_SYSTEM_NAME] = 'sqlite';
            $attributes[TraceAttributes::DB_NAMESPACE] = substr($dsn, 7);

            return $attributes;
        } elseif (str_starts_with($dsn, 'sqlite')) {
            $attributes[TraceAttributes::DB_SYSTEM_NAME] = 'sqlite';
            $attributes[TraceAttributes::DB_NAMESPACE] = $dsn;

            return $attributes;
        }

        // SQL Server format handling
        if (str_starts_with($dsn, 'sqlsrv:')) {
            if (preg_match('/Server=([^,;]+)(?:,([0-9]+))?/', $dsn, $serverMatches)) {
                $server = $serverMatches[1];
                if ($server !== '') {
                    $attributes[TraceAttributes::SERVER_ADDRESS] = $server;
                }

                if (isset($serverMatches[2]) && $serverMatches[2] !== '') {
                    $attributes[TraceAttributes::SERVER_PORT] = (int) $serverMatches[2];
                }
            }

            if (preg_match('/Database=([^;]*)/', $dsn, $dbMatches)) {
                $dbname = $dbMatches[1];
                if ($dbname !== '') {
                    $attributes[TraceAttributes::DB_NAMESPACE] = $dbname;
                }
            }

            return $attributes;
        }

        //deprecated, no replacement at this time
        /*if (preg_match('/user=([^;]*)/', $dsn, $matches)) {
            $user = $matches[1];
            if ($user !== '') {
                $attributes[TraceAttributes::DB_USER] = $user;
            }
        }*/

        // Extract host information
        if (preg_match('/host=([^;]*)/', $dsn, $matches)) {
            $host = $matches[1];
            if ($host !== '') {
                $attributes[TraceAttributes::SERVER_ADDRESS] = $host;
            }
        } elseif (preg_match('/mysql:([^;:]+)/', $dsn, $hostMatches)) {
            $host = $hostMatches[1];
            if ($host !== '' && $host !== 'dbname') {
                $attributes[TraceAttributes::SERVER_ADDRESS] = $host;
            }
        }

        // Extract port information
        if (preg_match('/port=([0-9]+)/', $dsn, $portMatches)) {
            $port = (int) $portMatches[1];
            $attributes[TraceAttributes::SERVER_PORT] = $port;
        } elseif (preg_match('/[.0-9]+:([0-9]+)/', $dsn, $portMatches)) {
            // This pattern matches IP:PORT format like 127.0.0.1:3308
            $port = (int) $portMatches[1];
            $attributes[TraceAttributes::SERVER_PORT] = $port;
        } elseif (preg_match('/:([0-9]+)/', $dsn, $portMatches)) {
            $port = (int) $portMatches[1];
            $attributes[TraceAttributes::SERVER_PORT] = $port;
        }

        // Extract database name
        if (preg_match('/dbname=([^;]*)/', $dsn, $matches)) {
            $dbname = $matches[1];
            if ($dbname !== '') {
                $attributes[TraceAttributes::DB_NAMESPACE] = $dbname;
            }
        }

        return $attributes;
    }
}
