<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\MySqli\Integration;

use OpenTelemetry\Contrib\Instrumentation\MySqli\MySqliTracker;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MySqliTrackerTest extends TestCase
{
    private MySqliTracker $tracker;
    private ReflectionMethod $splitQueries;

    public function setUp(): void
    {
        $this->tracker = new MySqliTracker();

        $this->splitQueries = new ReflectionMethod(MySqliTracker::class, 'splitQueries');
        $this->splitQueries->setAccessible(true);
    }

    public function tearDown(): void
    {
        unset($this->tracker, $this->splitQueries);
    }

    public function test_split_queries(): void
    {
        $query = "SELECT * FROM users; INSERT INTO logs (message) VALUES ('test');";

        $result = $this->splitQueries->invoke($this->tracker, $query);

        $expected = [
            'SELECT * FROM users;',
            "INSERT INTO logs (message) VALUES ('test');",
        ];

        $this->assertEquals($expected, $result);
    }

    public function test_split_queries_whitespaces(): void
    {
        $query = "        SELECT * FROM users;\n\t INSERT INTO logs (message) VALUES ('test');SELECT * from test\n\n";

        $result = $this->splitQueries->invoke($this->tracker, $query);

        $expected = [
            'SELECT * FROM users;',
            "INSERT INTO logs (message) VALUES ('test');",
            'SELECT * from test',
        ];

        $this->assertEquals($expected, $result);
    }

    public function test_split_queries_with_binding(): void
    {
        $query = '
            INSERT INTO `tableA` (columnA) VALUES (\'hello; world\'); SELECT LAST_INSERT_ID();
            INSERT INTO `tableA` (columnA) VALUES (?); SELECT LAST_INSERT_ID();
            INSERT INTO `tableA` (columnA) VALUES (\'hel\\\'lo; \\"world\'); SELECT LAST_INSERT_ID();
        ';

        $result = $this->splitQueries->invoke($this->tracker, $query);

        $expected = [
            "INSERT INTO `tableA` (columnA) VALUES ('hello; world');",
            'SELECT LAST_INSERT_ID();',
            'INSERT INTO `tableA` (columnA) VALUES (?);',
            'SELECT LAST_INSERT_ID();',
            "INSERT INTO `tableA` (columnA) VALUES ('hel\'lo; \\\"world');",
            'SELECT LAST_INSERT_ID();',
        ];

        $this->assertEquals($expected, $result);
    }

    public function test_split_queries_with_begin_end(): void
    {
        $query = "
            DROP PROCEDURE IF EXISTS get_data_with_delay;
            CREATE PROCEDURE get_data_with_delay()
            BEGIN
                -- first result
                SELECT SLEEP(1);
                -- second result
                SELECT 'Result 1' AS message;
                -- third result
                SELECT SLEEP(1);
                -- fourth result
                SELECT 'Result 2' AS message;
            END;

            SELECT * FROM users
        ";

        $result = $this->splitQueries->invoke($this->tracker, $query);

        $expected = [
            'DROP PROCEDURE IF EXISTS get_data_with_delay;',
            "CREATE PROCEDURE get_data_with_delay()
            BEGIN
                -- first result
                SELECT SLEEP(1);
                -- second result
                SELECT 'Result 1' AS message;
                -- third result
                SELECT SLEEP(1);
                -- fourth result
                SELECT 'Result 2' AS message;
            END;",
            'SELECT * FROM users',
        ];

        $this->assertEquals($expected, $result);
    }

    public function test_split_queries_with_labeled_begin_end(): void
    {
        $query = "
            DROP PROCEDURE IF EXISTS get_data_with_delay;
            CREATE PROCEDURE get_data_with_delay()
            BEGIN label;
                -- first result
                SELECT SLEEP(1);
                -- second result
                SELECT 'Result 1' AS message;
                -- third result
                SELECT SLEEP(1);
                -- fourth result
                SELECT 'Result 2' AS message;
            END label;

            SELECT * FROM users
        ";

        $result = $this->splitQueries->invoke($this->tracker, $query);

        $expected = [
            'DROP PROCEDURE IF EXISTS get_data_with_delay;',
            "CREATE PROCEDURE get_data_with_delay()
            BEGIN label;
                -- first result
                SELECT SLEEP(1);
                -- second result
                SELECT 'Result 1' AS message;
                -- third result
                SELECT SLEEP(1);
                -- fourth result
                SELECT 'Result 2' AS message;
            END label;",
            'SELECT * FROM users',
        ];

        $this->assertEquals($expected, $result);
    }

    public function test_split_queries_with_transaction(): void
    {
        $query = "
            SELECT * FROM users;
            BEGIN TRANSACTION;
            INSERT INTO users (name) VALUES ('Alice');
            INSERT INTO users (name) VALUES ('Bob');
            END TRANSACTION;
            SELECT * FROM users2;
        ";

        $result = $this->splitQueries->invoke($this->tracker, $query);

        $expected = [
            'SELECT * FROM users;',
            "BEGIN TRANSACTION;
            INSERT INTO users (name) VALUES ('Alice');
            INSERT INTO users (name) VALUES ('Bob');
            END TRANSACTION;",
            'SELECT * FROM users2;',
        ];

        $this->assertEquals($expected, $result);
    }

}
