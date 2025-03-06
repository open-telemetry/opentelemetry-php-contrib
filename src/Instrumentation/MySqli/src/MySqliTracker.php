<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\MySqli;

use mysqli;
use mysqli_stmt;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\SemConv\TraceAttributes;
use WeakMap;
use WeakReference;

/**
 * @phan-file-suppress PhanNonClassMethodCall
 */
final class MySqliTracker
{

    private WeakMap $mySqliToAttributes;
    private WeakMap $mySqliToMultiQueries;
    private WeakMap $statementToMySqli;
    private WeakMap $statementAttributes;
    private WeakMap $statementSpan;
    private WeakMap $mySqliSpan;
    private WeakMap $mySqliTransaction;

    public function __construct()
    {
        // /** @psalm-suppress PropertyTypeCoercion */
        $this->mySqliToAttributes = new WeakMap();
        $this->mySqliToMultiQueries = new WeakMap();
        $this->statementToMySqli = new WeakMap();
        $this->statementAttributes = new WeakMap();
        $this->statementSpan = new WeakMap();
        $this->mySqliSpan = new WeakMap();
        $this->mySqliTransaction = new WeakMap();
    }

    public function storeMySqliMultiQuery(mysqli $mysqli, string $query)
    {
        $this->mySqliToMultiQueries[$mysqli] = $this->splitQueries($query);
    }

    public function getNextMySqliMultiQuery(mysqli $mysqli) : ?string
    {
        if (!$this->mySqliToMultiQueries->offsetExists($mysqli)) {
            return null;
        }

        return array_shift($this->mySqliToMultiQueries[$mysqli]);
    }

    public function storeMySqliAttributes(mysqli $mysqli, ?string $hostname = null, ?string $username = null, ?string $database = null, ?int $port = null, ?string $socket = null)
    {
        $attributes = [];
        $attributes[TraceAttributes::DB_SYSTEM_NAME] = 'mysql';
        $attributes[TraceAttributes::SERVER_ADDRESS] = $hostname ?? get_cfg_var('mysqli.default_host');
        $attributes[TraceAttributes::SERVER_PORT] = $port ?? get_cfg_var('mysqli.default_port');
        //$attributes[TraceAttributes::DB_USER] = $username ?? get_cfg_var('mysqli.default_user'); //deprecated, no replacment at this time
        if ($database) {
            $attributes[TraceAttributes::DB_NAMESPACE] = $database;
        }
        $this->mySqliToAttributes[$mysqli] = $attributes;
    }

    public function addMySqliAttribute($mysqli, string $attribute, bool|int|float|string|array|null $value)
    {
        if (!$this->mySqliToAttributes->offsetExists($mysqli)) {
            $this->mySqliToAttributes[$mysqli] = [];
        }
        $this->mySqliToAttributes[$mysqli][$attribute] = $value;
    }

    public function getMySqliAttributes(mysqli $mysqli) : array
    {
        return $this->mySqliToAttributes[$mysqli] ?? [];
    }

    public function trackMySqliFromStatement(mysqli $mysqli, mysqli_stmt $mysqli_stmt)
    {
        $this->statementToMySqli[$mysqli_stmt] = WeakReference::create($mysqli);
    }

    public function getMySqliFromStatement(mysqli_stmt $mysqli_stmt) : ?mysqli
    {
        return ($this->statementToMySqli[$mysqli_stmt] ?? null)?->get();
    }

    public function getMySqliAttributesFromStatement(mysqli_stmt $stmt) : array
    {
        $mysqli = ($this->statementToMySqli[$stmt] ?? null)?->get();
        if (!$mysqli) {
            return [];
        }

        return $this->getMySqliAttributes($mysqli);
    }

    public function addStatementAttribute(mysqli_stmt $stmt, string $attribute, bool|int|float|string|array|null $value)
    {
        if (!$this->statementAttributes->offsetExists($stmt)) {
            $this->statementAttributes[$stmt] = [];
        }
        $this->statementAttributes[$stmt][$attribute] = $value;
    }

    public function getStatementAttributes(mysqli_stmt $stmt) : array
    {
        if (!$this->statementAttributes->offsetExists($stmt)) {
            return [];
        }

        return $this->statementAttributes[$stmt];
    }

    public function trackStatementSpan(mysqli_stmt $stmt, SpanContextInterface $spanContext)
    {
        $this->statementSpan[$stmt] = WeakReference::create($spanContext);
    }

    public function getStatementSpan(mysqli_stmt $stmt) : ?SpanContextInterface
    {
        if (!$this->statementSpan->offsetExists($stmt)) {
            return null;
        }

        return $this->statementSpan[$stmt]->get();
    }

    public function trackMysqliSpan(mysqli $mysqli, SpanContextInterface $spanContext)
    {
        $this->mySqliSpan[$mysqli] = WeakReference::create($spanContext);
    }

    public function getMySqliSpan(mysqli $mysqli) : ?SpanContextInterface
    {
        if (!$this->mySqliSpan->offsetExists($mysqli)) {
            return null;
        }

        return $this->mySqliSpan[$mysqli]->get();
    }

    public function trackMySqliTransaction(mysqli $mysqli, SpanContextInterface $spanContext)
    {
        $this->mySqliTransaction[$mysqli] = WeakReference::create($spanContext);
    }

    public function getMySqliTransaction(mysqli $mysqli) : ?SpanContextInterface
    {
        if (!$this->mySqliTransaction->offsetExists($mysqli)) {
            return null;
        }

        return $this->mySqliTransaction[$mysqli]->get();
    }

    public function untrackMySqliTransaction(mysqli $mysqli)
    {
        if ($this->mySqliTransaction->offsetExists($mysqli)) {
            unset($this->mySqliTransaction[$mysqli]);
        }
    }

    private function splitQueries(string $sql)
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

}
