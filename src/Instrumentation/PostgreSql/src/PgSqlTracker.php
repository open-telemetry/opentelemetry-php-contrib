<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\PostgreSql;

use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\SemConv\TraceAttributes;
use PgSql\Connection;
use PgSql\Lob;
use SplQueue;
use WeakMap;
use WeakReference;

/**
 * @phan-file-suppress PhanNonClassMethodCall
 */
final class PgSqlTracker
{

    private WeakMap $connectionAttributes;

    /**
     * WeakMap<Connection, string>
    */
    private WeakMap $connectionStatements;

    /**
     * WeakMap<Connection, SplQueue<WeakReference<?SpanContextInterface>>>
    */
    private WeakMap $connectionAsyncLink;

    /**
     * WeakMap<Lob, WeakReference<Connection>>
    */
    private WeakMap $connectionLargeObjects;

    public function __construct()
    {
        // /** @psalm-suppress PropertyTypeCoercion */
        $this->connectionAttributes = new WeakMap();
        $this->connectionStatements = new WeakMap();
        $this->connectionAsyncLink = new WeakMap(); // maps connection to SplQueue with links
        $this->connectionLargeObjects = new WeakMap(); // maps Lob to Connection
    }

    public function addAsyncLinkForConnection(Connection $connection, SpanContextInterface $spanContext)
    {

        if (!$this->connectionAsyncLink->offsetExists($connection)) {
            $this->connectionAsyncLink[$connection] = new SplQueue();
        }
        $this->connectionAsyncLink[$connection]->push(WeakReference::create($spanContext));
    }

    public function getAsyncLinkForConnection(Connection $connection) : ?SpanContextInterface
    {
        if (!$this->connectionAsyncLink->offsetExists($connection)) {
            return null;
        }

        if ($this->connectionAsyncLink[$connection]->isEmpty()) {
            return null;
        }

        return $this->connectionAsyncLink[$connection]->pop()->get();
    }

    public function addConnectionStatement(Connection $connection, string $statementName, string $query)
    {
        if (!$this->connectionStatements->offsetExists($connection)) {
            $this->connectionStatements[$connection] = [];
        }
        $this->connectionStatements[$connection][$statementName] = $query;
    }

    public function getStatementQuery(Connection $connection, string $statementName) : ?string
    {
        if ($this->connectionStatements->offsetExists($connection)) {
            return $this->connectionStatements[$connection][$statementName] ?? null;
        }

        return null;
    }

    public function storeConnectionAttributes(Connection $connection, string $connectionString)
    {
        $this->connectionAttributes[$connection] = self::parseAttributesFromConnectionString($connectionString);
    }
    public function getConnectionAttributes(Connection $connection) : array
    {
        return $this->connectionAttributes[$connection] ?? [];
    }

    public function trackConnectionFromLob(Connection $connection, Lob $lob)
    {
        $this->connectionLargeObjects[$lob] = WeakReference::create($connection);
    }

    public function getConnectionFromLob(Lob $lob) : ?Connection
    {
        if ($this->connectionLargeObjects->offsetExists($lob)) {
            return $this->connectionLargeObjects[$lob]->get();
        }

        return null;
    }

    public static function splitQueries(string $sql)
    {
        // Normalize line endings to \n
        $sql = preg_replace("/\r\n|\n\r|\r/", "\n", $sql);
        if ($sql === null) {
            return [];
        }

        $queries = [];
        $buffer = '';
        $blockDepth = 0;
        $tokens = preg_split('/(;)/', $sql, -1, PREG_SPLIT_DELIM_CAPTURE); // Keep semicolons as separate tokens

        $singleQuotes = 0;
        $doubleQuotes = 0;

        if (empty($tokens)) {
            return [];
        }

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            $tokenLen = strlen($token);
            for ($i = 0; $i < $tokenLen; $i++) {
                if ($token[$i] == "'" && ($token[$i - 1] ?? false) !== '\\') {
                    $singleQuotes++;
                }
                if ($token[$i] == '"' && ($token[$i - 1] ?? false) !== '\\') {
                    $doubleQuotes++;
                }
            }

            $buffer .= $token;

            // Detect BEGIN with optional label
            if (preg_match('/(^|\s|[)])\bBEGIN\b/i', $token)) {
                $blockDepth++;
            }

            // Detect END with optional label
            if (preg_match('/\bEND\b(\s+[a-zA-Z0-9_]+)?\s*$/i', $token)) {
                $blockDepth--;
            }

            // we're somewhere inside qoutes
            if (($singleQuotes % 2) != 0 || ($doubleQuotes % 2) != 0) {
                continue;
            }

            // If we are outside a block and encounter a semicolon, split the query
            if ($blockDepth === 0 && $token === ';') {
                $trimmedQuery = trim($buffer);
                if ($trimmedQuery !== ';') { // Ignore empty queries
                    $queries[] = $trimmedQuery;
                }
                $buffer = '';
                $singleQuotes = 0;
                $doubleQuotes = 0;
            }
        }

        // Add any remaining buffer as a query
        if (!empty(trim($buffer))) {
            $queries[] = trim($buffer);
        }

        return $queries;
    }

    public static function parsePgConnString(string $conninfo): array
    {
        $result = [];
        $length = strlen($conninfo);
        $i = 0;

        while ($i < $length) {
            // Skip leading whitespace
            while ($i < $length && ctype_space($conninfo[$i])) {
                $i++;
            }

            // Read the key until '=' or whitespace
            $key = '';
            while ($i < $length && $conninfo[$i] !== '=' && !ctype_space($conninfo[$i])) {
                $key .= $conninfo[$i++];
            }

            // Skip whitespace before '='
            while ($i < $length && ctype_space($conninfo[$i])) {
                $i++;
            }

            // Expect '=' after key
            if ($i >= $length || $conninfo[$i] !== '=') {
                // throw new \InvalidArgumentException("Expected '=' after key '$key'");
                //TODO verify - it might be socket name
            }
            $i++; // Move past '='

            // Skip whitespace after '='
            while ($i < $length && ctype_space($conninfo[$i])) {
                $i++;
            }

            $value = '';

            // Handle quoted value (single or double quotes)
            if ($i < $length && ($conninfo[$i] === '\'' || $conninfo[$i] === '"')) {
                $quote = $conninfo[$i++];
                while ($i < $length) {
                    if ($conninfo[$i] === '\\' && $i + 1 < $length) {
                        // Handle escaped character
                        $value .= $conninfo[++$i];
                    } elseif ($conninfo[$i] === $quote) {
                        // End of quoted value
                        $i++;

                        break;
                    } else {
                        $value .= $conninfo[$i++];
                    }
                }
            } else {
                // Handle unquoted value
                while ($i < $length && !ctype_space($conninfo[$i])) {
                    if ($conninfo[$i] === '\\' && $i + 1 < $length) {
                        // Handle escaped character
                        $value .= $conninfo[++$i];
                    } else {
                        $value .= $conninfo[$i++];
                    }
                }
            }

            // Only store non-empty keys
            if ($key !== '') {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public static function parseAttributesFromConnectionString(string $connectionString)
    {
        $connectionData = self::parsePgConnString($connectionString);

        $addr = $connectionData['host'] ?? $connectionData['hostaddr'] ?? null;
        $attributes = [];
        $attributes[TraceAttributes::SERVER_ADDRESS] = $addr;
        $attributes[TraceAttributes::SERVER_PORT] = $addr !== null ? ($connectionData['port'] ?? null) : null;
        $attributes[TraceAttributes::DB_NAMESPACE] = $connectionData['dbname'] ?? $connectionData['user'] ?? null;
        $attributes[TraceAttributes::DB_SYSTEM_NAME] =  'postgresql';

        return $attributes;
    }

}
