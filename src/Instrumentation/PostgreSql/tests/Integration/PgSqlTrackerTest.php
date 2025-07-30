<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\PostgreSql\Integration;

use OpenTelemetry\Contrib\Instrumentation\PostgreSql\PgSqlTracker;
use PHPUnit\Framework\TestCase;

class PgSqlTrackerTest extends TestCase
{
    public function test_query_split(): void
    {
        $sql = 'SELECT 1; SELECT 2;';
        $queries = PgSqlTracker::splitQueries($sql);

        $this->assertSame(['SELECT 1;', 'SELECT 2;'], $queries);
    }

    public function test_parse_standard_connection_string(): void
    {
        $result = PgSqlTracker::parsePgConnString('host=localhost port=5432 dbname=mydb user=otel password=secret');

        $this->assertSame('localhost', $result['host']);
        $this->assertSame('5432', $result['port']);
        $this->assertSame('mydb', $result['dbname']);
        $this->assertSame('otel', $result['user']);
        $this->assertSame('secret', $result['password']);
    }

    public function test_parse_quoted_values(): void
    {
        $result = PgSqlTracker::parsePgConnString("host='localhost' dbname=\"my db\" user='user name'");

        $this->assertSame('localhost', $result['host']);
        $this->assertSame('my db', $result['dbname']);
        $this->assertSame('user name', $result['user']);
    }
    public function test_parse_socket_only(): void
    {
        $result = PgSqlTracker::parsePgConnString('dbname=mydb user=postgres');

        $this->assertArrayNotHasKey('host', $result);
        $this->assertSame('mydb', $result['dbname']);
        $this->assertSame('postgres', $result['user']);
    }

    public function test_parse_empty_string(): void
    {
        $result = PgSqlTracker::parsePgConnString('');
        $this->assertSame([], $result);
    }

    public function test_parse_attributes_from_connstring_with_host(): void
    {
        $connString = 'host=localhost port=5432 dbname=testdb user=otel';
        $attrs = PgSqlTracker::parseAttributesFromConnectionString($connString);

        $this->assertSame('localhost', $attrs['server.address']);
        $this->assertSame('5432', $attrs['server.port']);
        $this->assertSame('testdb', $attrs['db.namespace']);
        $this->assertSame('postgresql', $attrs['db.system.name']);
    }

    public function test_parse_attributes_from_connstring_socket(): void
    {
        $connString = 'dbname=testdb user=otel';
        $attrs = PgSqlTracker::parseAttributesFromConnectionString($connString);

        $this->assertNull($attrs['server.address']);
        $this->assertSame('testdb', $attrs['db.namespace']);
        $this->assertSame('postgresql', $attrs['db.system.name']);
    }

    public function test_basic_split(): void
    {
        $sql = "SELECT * FROM users; INSERT INTO logs (message) VALUES ('test');";
        $expected = [
            'SELECT * FROM users;',
            "INSERT INTO logs (message) VALUES ('test');",
        ];

        $result = PgSqlTracker::splitQueries($sql);
        $this->assertEquals($expected, $result);
    }

    public function test_whitespace_variants(): void
    {
        $sql = "  SELECT 1;\n\nINSERT INTO test VALUES (2); SELECT 3";
        $expected = [
            'SELECT 1;',
            'INSERT INTO test VALUES (2);',
            'SELECT 3',
        ];

        $result = PgSqlTracker::splitQueries($sql);
        $this->assertEquals($expected, $result);
    }

    public function test_semicolon_in_quotes(): void
    {
        $sql = "INSERT INTO x (text) VALUES ('abc;def'); SELECT 1;";
        $expected = [
            "INSERT INTO x (text) VALUES ('abc;def');",
            'SELECT 1;',
        ];

        $result = PgSqlTracker::splitQueries($sql);
        $this->assertEquals($expected, $result);
    }
    public function test_empty_input(): void
    {
        $sql = "\n\n\t  ";
        $expected = [];
        $result = PgSqlTracker::splitQueries($sql);
        $this->assertEquals($expected, $result);
    }

}
