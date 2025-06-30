<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Doctrine;

use Exception;

final class AttributesResolver
{
    /**
     *
     * Values:
     * See list of well-known values at https://opentelemetry.io/docs/specs/semconv/database/sql/
     *
     * Keys:
     * optional driver names used in Doctrine to indicate the well-known values to use in opentelemetry
     * See list of available doctrine drivers: https://www.doctrine-project.org/projects/doctrine-dbal/en/4.2/reference/configuration.html#driver
     *
     * Multiple entries can be created for the same well-known value
     */
    private const DB_SYSTEMS_KNOWN = [
        'cockroachdb',
        'ibm_db2' => 'db2',
        'derby',
        'edb',
        'firebird',
        'h2',
        'hsqldb',
        'ingres',
        'interbase',
        'mariadb',
        'maxdb',
        'sqlsrv' => 'mssql',
        'mssqlcompact',
        'mysqli' => 'mysql',
        'oci8' => 'oracle',
        'pervasive',
        'pgsql' => 'postgresql',
        'sqlite3' => 'sqlite',
        'trino',
    ];

    public static function get(string $attributeName, array $params): string|int|null
    {
        $method = 'get' . str_replace('.', '', ucwords($attributeName, '.'));

        if (!method_exists(AttributesResolver::class, $method)) {
            throw new Exception(sprintf('Attribute %s not supported by Doctrine', $attributeName));
        }

        return self::{$method}($params);
    }

    /**
     * Resolve attribute `server.address`
     */
    private static function getServerAddress(array $params): ?string
    {
        return $params[1][0]['host'] ?? null;
    }

    /**
     * Resolve attribute `server.port`
     */
    private static function getServerPort(array $params): ?int
    {
        $port = $params[1][0]['port'] ?? null;
        if ($port) {
            $port = (int) $port;
        }

        return $port;
    }

    /**
     * Resolve attribute `db.system.name`
     */
    private static function getDbSystemName(array $params)
    {
        $dbSystem = $params[1][0]['driver'] ?? null;

        if ($dbSystem && strpos($dbSystem, 'pdo_') !== false) {
            // Remove pdo_ word to ignore it while searching well-known db.system
            $dbSystem = ltrim($dbSystem, 'pdo_');
        }

        if (in_array($dbSystem, self::DB_SYSTEMS_KNOWN)) {
            return $dbSystem;
        }

        // Fetch the db system using the alias if exists
        if (isset(self::DB_SYSTEMS_KNOWN[$dbSystem])) {
            return self::DB_SYSTEMS_KNOWN[$dbSystem];
        }

        return 'other_sql';
    }

    /**
     * Resolve attribute `db.collection.name`
     */
    private static function getDbCollectionName(array $params): string
    {
        return $params[1][0]['dbname'] ?? 'unknown';
    }

    /**
     * Resolve attribute `db.query.text`
     * No sanitization is implemented because implicitly the query is expected to be expressed as a prepared statement
     * which happen automatically in Doctrine if parameters are bound to the query.
     */
    private static function getDbQueryText(array $params): string
    {
        return $params[1][0] ?? 'undefined';
    }

    private static function getDbNamespace(array $params): ?string
    {
        return $params[1][0]['dbname'] ?? null;
    }

    public static function getTarget(array $params): ?string
    {
        $query = $params[0] ?? null;

        if (!$query) {
            return null;
        }

        // Fetch target name
        $matches = [];
        preg_match_all('/( from| into| update| join)\s*([a-zA-Z0-9`"[\]_]+)/i', $query, $matches);

        $targetName = null;
        if ($matches !== []) {
            $targetName = $matches[2][0] ?? null;
        }
        if ($targetName === null) {
            return null;
        }
        //strip quotes and backticks from the target name
        $targetName = str_replace(['`', '"', '[', ']'], '', $targetName);

        return $targetName;
    }

    /**
     * Resolve attribute `db.query.summary`
     * See https://opentelemetry.io/docs/specs/semconv/database/database-spans/#generating-a-summary-of-the-query-text
     */
    public static function getDbQuerySummary(array $params): string
    {
        $operationName = self::getDbOperationName($params);
        $targetName = self::getTarget($params);

        return $operationName . ($targetName ? ' ' . $targetName : '');
    }

    public static function getDbOperationName(array $params): string
    {
        $query = $params[0] ?? null;

        if (!$query) {
            return '';
        }

        // Fetch operation name
        $operationName = explode(' ', $query);
        $operationName = $operationName[0];

        return $operationName;
    }
}
