<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\PDO;

use OpenTelemetry\SemConv\TraceAttributes;

final class PDOAttributeTracker
{
    /**
     * @var \WeakMap<\PDO, iterable<non-empty-string, bool|int|float|string|array|null>>
     */
    private \WeakMap $pdoToAttributesMap;
    /**
     * @var \WeakMap<\PDOStatement, int>
     */
    private \WeakMap $statementMapToPdoMap;

    public function __construct()
    {
        /** @psalm-suppress PropertyTypeCoercion */
        $this->pdoToAttributesMap = new \WeakMap();
        /** @psalm-suppress PropertyTypeCoercion */
        $this->statementMapToPdoMap = new \WeakMap();
    }

    public function trackStatementToPdoMapping(\PDOStatement $statement, \PDO $pdo)
    {
        $this->statementMapToPdoMap[$statement] = spl_object_id($pdo);
    }

    /**
     * Maps a statement back to the connection attributes.
     *
     * @param \PDOStatement $statement
     * @return iterable<non-empty-string, bool|int|float|string|array|null>
     */
    public function trackedAttributesForStatement(\PDOStatement $statement): iterable
    {
        if (!$this->statementMapToPdoMap->offsetExists($statement)) {
            return [];
        }

        $pdoKey = $this->statementMapToPdoMap[$statement];

        foreach ($this->pdoToAttributesMap as $pdo => $attributes) {
            if (spl_object_id($pdo) === $pdoKey) {
                return $attributes;
            }
        }

        return [];
    }

    /**
     * @param \PDO $pdo
     * @return iterable<non-empty-string, bool|int|float|string|array|null>
     */
    public function trackPdoAttributes(\PDO $pdo): iterable
    {
        $attributes = [];

        try {
            $dbSystem = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $attributes[TraceAttributes::DB_SYSTEM] = self::mapDriverNameToAttribute($dbSystem);
        } catch (\Error $e) {
            // if we catched an exception, the driver is likely not supporting the operation, default to "other"
            $attributes[TraceAttributes::DB_SYSTEM] = 'other_sql';
        }

        return $this->pdoToAttributesMap[$pdo] = $attributes;
    }

    public function trackedAttributesForPdo(\PDO $pdo)
    {
        return $this->pdoToAttributesMap[$pdo] ?? [];
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
            'sqlite' => $driverName,
            'sqlsrv' => 'mssql',
            'oci' => 'oracle',
            'ibm' => 'db2',
            default => 'other_sql',
        };
    }
}
