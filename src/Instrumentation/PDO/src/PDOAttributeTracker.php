<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\PDO;

use OpenTelemetry\SemConv\TraceAttributes;

final class PDOAttributeTracker
{
    private $pdoToAttributesMap;
    private $statementMapToPdoMap;

    public function __construct()
    {
        $this->pdoToAttributesMap = [];
        $this->statementMapToPdoMap = [];
    }

    public function trackStatementToPdoMapping(\PDOStatement $statement, \PDO $pdo)
    {
        $this->statementMapToPdoMap[spl_object_id($statement)] = spl_object_id($pdo);
    }

    public function removeMapping(\PDOStatement $statement)
    {
        unset($this->statementMapToPdoMap[spl_object_id($statement)]);
    }

    /**
     * Maps a statement back to the connection attributes.
     *
     * @param \PDOStatement $statement
     * @return iterable<non-empty-string, bool|int|float|string|array|null>
     */
    public function trackedAttributesForStatement(\PDOStatement $statement): iterable
    {
        $statementKey = spl_object_id($statement);
        if (!array_key_exists($statementKey, $this->statementMapToPdoMap)) {
            return [];
        }

        $pdoKey = $this->statementMapToPdoMap[$statementKey];
        if (!array_key_exists($pdoKey, $this->pdoToAttributesMap)) {
            return [];
        }

        return $this->pdoToAttributesMap[$pdoKey];
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

        $this->pdoToAttributesMap[spl_object_id($pdo)] = $attributes;

        return $this->pdoToAttributesMap[spl_object_id($pdo)];
    }

    public function trackedAttributesForPdo(\PDO $pdo)
    {
        $key = spl_object_id($pdo);

        return array_key_exists($key, $this->pdoToAttributesMap) ? $this->pdoToAttributesMap[$key] : [];
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
        switch ($driverName) {
            case 'mysql':
                return 'mysql';
            case 'pgsql':
                return 'postgresql';
            case 'sqlite':
                return $driverName;
            case 'sqlsrv':
                return 'mssql';
            case 'oci':
                return 'oracle';
            case 'ibm':
                return 'db2';
            default:
                return 'other_sql';
        }
    }
}
