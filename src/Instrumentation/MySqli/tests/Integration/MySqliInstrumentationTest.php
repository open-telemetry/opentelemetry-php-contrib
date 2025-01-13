<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\MySqli\Integration;

use ArrayObject;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use mysqli_stmt;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\TraceAttributes;
use PHPUnit\Framework\TestCase;
use Throwable;

class MySqliInstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    /** @var ArrayObject<int, ImmutableSpan> */
    private ArrayObject $storage;

    private string $mysqlHost;

    private string $user;
    private string $passwd;
    private string $database;

    public function setUp(): void
    {
        $this->storage = new ArrayObject();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage)
            )
        );

        $this->scope = Configurator::create()
            ->withTracerProvider($tracerProvider)
            ->withPropagator(TraceContextPropagator::getInstance())
            ->activate();

        $this->mysqlHost = getenv('MYSQL_HOST') ?: '127.0.0.1';

        $this->user = 'otel_user';
        $this->passwd = 'otel_passwd';
        $this->database = 'otel_db';
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    private function assertDatabaseAttributes(int $offset)
    {
        $span = $this->storage->offsetGet($offset);
        $this->assertEquals($this->mysqlHost, $span->getAttributes()->get(TraceAttributes::SERVER_ADDRESS));
        $this->assertEquals($this->user, $span->getAttributes()->get(TraceAttributes::DB_USER));
        $this->assertEquals($this->database, $span->getAttributes()->get(TraceAttributes::DB_NAMESPACE));
        $this->assertEquals('mysql', $span->getAttributes()->get(TraceAttributes::DB_SYSTEM));
    }

    private function assertDatabaseAttributesForAllSpans(int $offsets)
    {
        for ($offset = 0; $offset < $offsets; $offset++) {
            $this->assertDatabaseAttributes($offset);
        }
    }

    private function assertAttributes(int $offset, iterable $attributes)
    {
        foreach ($attributes as $attribute => $expected) {
            $this->assertSame($expected, $this->storage->offsetGet($offset)->getAttributes()->get($attribute));
        }
    }

    public function test_mysqli_connect(): void
    {
        $mysqli = new mysqli($this->mysqlHost, $this->user, $this->passwd, $this->database);
        $mysqli->connect($this->mysqlHost, $this->user, $this->passwd, $this->database);
        mysqli_connect($this->mysqlHost, $this->user, $this->passwd, $this->database);

        $mysqli->real_connect($this->mysqlHost, $this->user, $this->passwd, $this->database);
        mysqli_real_connect($mysqli, $this->mysqlHost, $this->user, $this->passwd, $this->database);

        $offset = 0;
        $this->assertSame('mysqli::__construct', $this->storage->offsetGet($offset++)->getName());
        $this->assertSame('mysqli::connect', $this->storage->offsetGet($offset++)->getName());
        $this->assertSame('mysqli_connect', $this->storage->offsetGet($offset++)->getName());
        $this->assertSame('mysqli::real_connect', $this->storage->offsetGet($offset++)->getName());
        $this->assertSame('mysqli_real_connect', $this->storage->offsetGet($offset++)->getName());

        $this->assertCount($offset, $this->storage);

        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_mysqli_query_objective(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR| MYSQLI_REPORT_STRICT);

        $mysqli = new mysqli($this->mysqlHost, $this->user, $this->passwd, $this->database);

        $offset = 0;
        $this->assertSame('mysqli::__construct', $this->storage->offsetGet($offset)->getName());

        $offset++;
        $res = $mysqli->query('SELECT * FROM otel_db.users');
        if ($res instanceof mysqli_result) {
            while ($res->fetch_object()) {
            }
        }

        $this->assertSame('mysqli::query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT * FROM otel_db.users',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);

        $offset++;
        if ($mysqli->real_query('SELECT * FROM otel_db.users')) {
            $mysqli->store_result();
        }
        $this->assertSame('mysqli::real_query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT * FROM otel_db.users',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);

        $offset++;

        try {
            $mysqli->query('SELECT * FROM unknown_db.users');
        } catch (Throwable) {
        }

        $this->assertSame('mysqli::query', $this->storage->offsetGet($offset)->getName());
        $this->assertStringContainsString('Unknown database', $this->storage->offsetGet($offset)->getStatus()->getDescription());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT * FROM unknown_db.users',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::EXCEPTION_TYPE => mysqli_sql_exception::class,
        ]);

        $offset++;

        try {
            $mysqli->real_query('SELECT * FROM unknown_db.users');
        } catch (Throwable) {
        }

        $this->assertSame('mysqli::real_query', $this->storage->offsetGet($offset)->getName());
        $this->assertSame(StatusCode::STATUS_ERROR, $this->storage->offsetGet($offset)->getStatus()->getCode());
        $this->assertStringContainsString('Unknown database', $this->storage->offsetGet($offset)->getStatus()->getDescription());

        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT * FROM unknown_db.users',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::EXCEPTION_TYPE => mysqli_sql_exception::class,
        ]);

        // disabling exceptions - test error capturing
        mysqli_report(MYSQLI_REPORT_ERROR);
        $offset++;

        try {

            $mysqli->query('SELECT * FROM unknown_db.users');
        } catch (Throwable) {
        }

        $this->assertSame('mysqli::query', $this->storage->offsetGet($offset)->getName());
        $this->assertStringContainsString('Unknown database', $this->storage->offsetGet($offset)->getStatus()->getDescription());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT * FROM unknown_db.users',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::EXCEPTION_TYPE => \PHPUnit\Framework\Error\Warning::class,
        ]);

        $offset++;

        try {
            $mysqli->real_query('SELECT * FROM unknown_db.users');
        } catch (Throwable) {
        }

        $this->assertSame('mysqli::real_query', $this->storage->offsetGet($offset)->getName());
        $this->assertStringContainsString('Unknown database', $this->storage->offsetGet($offset)->getStatus()->getDescription());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT * FROM unknown_db.users',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::EXCEPTION_TYPE => \PHPUnit\Framework\Error\Warning::class,
        ]);

        $offset++;
        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_mysqli_query_procedural(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR| MYSQLI_REPORT_STRICT);

        $mysqli = mysqli_connect($this->mysqlHost, $this->user, $this->passwd, $this->database);

        $this->assertTrue($mysqli instanceof mysqli);

        $offset = 0;
        $this->assertSame('mysqli_connect', $this->storage->offsetGet($offset)->getName());

        $offset++;
        $res = mysqli_query($mysqli, 'SELECT * FROM otel_db.users');
        if ($res instanceof mysqli_result) {
            while ($res->fetch_object()) {
            }
        }

        $this->assertSame('mysqli_query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT * FROM otel_db.users',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);

        $offset++;
        if (mysqli_real_query($mysqli, 'SELECT * FROM otel_db.users')) {
            $mysqli->store_result();
        }
        $this->assertSame('mysqli_real_query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT * FROM otel_db.users',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);

        $offset++;

        try {
            mysqli_query($mysqli, 'SELECT * FROM unknown_db.users');
        } catch (Throwable) {
        }

        $this->assertSame('mysqli_query', $this->storage->offsetGet($offset)->getName());
        $this->assertStringContainsString('Unknown database', $this->storage->offsetGet($offset)->getStatus()->getDescription());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT * FROM unknown_db.users',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::EXCEPTION_TYPE => mysqli_sql_exception::class,
        ]);

        $offset++;

        try {
            mysqli_real_query($mysqli, 'SELECT * FROM unknown_db.users');
        } catch (Throwable) {
        }

        $this->assertSame('mysqli_real_query', $this->storage->offsetGet($offset)->getName());
        $this->assertSame(StatusCode::STATUS_ERROR, $this->storage->offsetGet($offset)->getStatus()->getCode());
        $this->assertStringContainsString('Unknown database', $this->storage->offsetGet($offset)->getStatus()->getDescription());

        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT * FROM unknown_db.users',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::EXCEPTION_TYPE => mysqli_sql_exception::class,
        ]);

        // disabling exceptions - test error capturing
        mysqli_report(MYSQLI_REPORT_ERROR);

        $offset++;

        try {
            mysqli_query($mysqli, 'SELECT * FROM unknown_db.users');
        } catch (Throwable) {
        }

        $this->assertSame('mysqli_query', $this->storage->offsetGet($offset)->getName());
        $this->assertStringContainsString('Unknown database', $this->storage->offsetGet($offset)->getStatus()->getDescription());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT * FROM unknown_db.users',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::EXCEPTION_TYPE => \PHPUnit\Framework\Error\Warning::class,
        ]);

        $offset++;

        try {
            mysqli_real_query($mysqli, 'SELECT * FROM unknown_db.users');
        } catch (Throwable) {
        }

        $this->assertSame('mysqli_real_query', $this->storage->offsetGet($offset)->getName());
        $this->assertStringContainsString('Unknown database', $this->storage->offsetGet($offset)->getStatus()->getDescription());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT * FROM unknown_db.users',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::EXCEPTION_TYPE => \PHPUnit\Framework\Error\Warning::class,
        ]);

        $offset++;
        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);

    }

    public function test_mysqli_execute_query_objective(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $mysqli = new mysqli($this->mysqlHost, $this->user, $this->passwd, $this->database);

        $offset = 0;
        $this->assertSame('mysqli::__construct', $this->storage->offsetGet($offset)->getName());

        $offset++;
        $result = $mysqli->execute_query('SELECT * FROM otel_db.users');
        if ($result instanceof mysqli_result) {
            $this->assertCount(3, $result->fetch_all(), 'Result should contain 3 elements');
        }

        $this->assertSame('mysqli::execute_query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT * FROM otel_db.users',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);

        $offset++;

        try {
            $result = $mysqli->execute_query('SELECT * FROM unknown_db.users');
        } catch (mysqli_sql_exception) {
        }

        $this->assertSame('mysqli::execute_query', $this->storage->offsetGet($offset)->getName());
        $this->assertStringContainsString('Unknown database', $this->storage->offsetGet($offset)->getStatus()->getDescription());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT * FROM unknown_db.users',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::EXCEPTION_TYPE => mysqli_sql_exception::class,
        ]);

        mysqli_report(MYSQLI_REPORT_ERROR);

        $offset++;

        try {
            $result = $mysqli->execute_query('SELECT * FROM unknown_db.users');
        } catch (Throwable) {
        }

        $this->assertSame('mysqli::execute_query', $this->storage->offsetGet($offset)->getName());
        $this->assertStringContainsString('Unknown database', $this->storage->offsetGet($offset)->getStatus()->getDescription());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT * FROM unknown_db.users',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::EXCEPTION_TYPE => \PHPUnit\Framework\Error\Warning::class,
        ]);

        $offset++;
        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_mysqli_execute_query_procedural(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $mysqli = mysqli_connect($this->mysqlHost, $this->user, $this->passwd, $this->database);

        $offset = 0;
        $this->assertSame('mysqli_connect', $this->storage->offsetGet($offset)->getName());

        $offset++;
        $result = mysqli_execute_query($mysqli, 'SELECT * FROM otel_db.users');
        if ($result instanceof mysqli_result) {
            $this->assertCount(3, $result->fetch_all(), 'Result should contain 3 elements');
        }

        $this->assertSame('mysqli_execute_query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT * FROM otel_db.users',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);

        $offset++;

        try {
            $result = mysqli_execute_query($mysqli, 'SELECT * FROM unknown_db.users');
        } catch (mysqli_sql_exception) {
        }

        $this->assertSame('mysqli_execute_query', $this->storage->offsetGet($offset)->getName());
        $this->assertStringContainsString('Unknown database', $this->storage->offsetGet($offset)->getStatus()->getDescription());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT * FROM unknown_db.users',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::EXCEPTION_TYPE => mysqli_sql_exception::class,
        ]);

        mysqli_report(MYSQLI_REPORT_ERROR);

        $offset++;

        try {
            $result = mysqli_execute_query($mysqli, 'SELECT * FROM unknown_db.users');
        } catch (Throwable) {
        }

        $this->assertSame('mysqli_execute_query', $this->storage->offsetGet($offset)->getName());
        $this->assertStringContainsString('Unknown database', $this->storage->offsetGet($offset)->getStatus()->getDescription());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT * FROM unknown_db.users',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::EXCEPTION_TYPE => \PHPUnit\Framework\Error\Warning::class,
        ]);

        $offset++;
        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_mysqli_multi_query_objective(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $mysqli = new mysqli($this->mysqlHost, $this->user, $this->passwd, $this->database);

        $offset = 0;
        $this->assertSame('mysqli::__construct', $this->storage->offsetGet($offset)->getName());

        $query = 'SELECT CURRENT_USER();';
        $query .= 'SELECT email FROM users ORDER BY id;';
        $query .= 'SELECT name FROM products ORDER BY stock;';
        $query .= 'SELECT test FROM unknown ORDER BY nothing;';

        $result = $mysqli->multi_query($query);
        do {
            try {
                if ($result = $mysqli->store_result()) {
                    $result->free_result();
                }

                if (!$mysqli->next_result()) {
                    break;
                }
            } catch (Throwable) {
                break;
            }
        } while (true);

        $offset++;
        $this->assertSame('mysqli::multi_query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT CURRENT_USER();',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);

        $offset++;
        $this->assertSame('mysqli::next_result', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT email FROM users ORDER BY id;',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);

        $offset++;
        $this->assertSame('mysqli::next_result', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT name FROM products ORDER BY stock;',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);

        $offset++;
        $this->assertSame('mysqli::next_result', $this->storage->offsetGet($offset)->getName());
        $this->assertStringContainsString("Table 'otel_db.unknown' doesn't exist", $this->storage->offsetGet($offset)->getStatus()->getDescription());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT test FROM unknown ORDER BY nothing;',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::EXCEPTION_TYPE => mysqli_sql_exception::class,
        ]);

        mysqli_report(MYSQLI_REPORT_ERROR);

        $result = $mysqli->multi_query($query);
        do {
            try {
                if ($result = $mysqli->store_result()) {
                    $result->free_result();
                }

                if (!$mysqli->next_result()) {
                    break;
                }
            } catch (Throwable) {
                break;
            }
        } while (true);

        $offset++;
        $this->assertSame('mysqli::multi_query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT CURRENT_USER();',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);

        $offset++;
        $this->assertSame('mysqli::next_result', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT email FROM users ORDER BY id;',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);

        $offset++;
        $this->assertSame('mysqli::next_result', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT name FROM products ORDER BY stock;',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);

        $offset++;
        $this->assertSame('mysqli::next_result', $this->storage->offsetGet($offset)->getName());
        $this->assertStringContainsString("Table 'otel_db.unknown' doesn't exist", $this->storage->offsetGet($offset)->getStatus()->getDescription());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT test FROM unknown ORDER BY nothing;',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::EXCEPTION_TYPE => \PHPUnit\Framework\Error\Warning::class,
        ]);

        $offset++;
        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_mysqli_multi_query_procedural(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $mysqli = mysqli_connect($this->mysqlHost, $this->user, $this->passwd, $this->database);

        $offset = 0;
        $this->assertSame('mysqli_connect', $this->storage->offsetGet($offset)->getName());

        $query = 'SELECT CURRENT_USER();';
        $query .= 'SELECT email FROM users ORDER BY id;';
        $query .= 'SELECT name FROM products ORDER BY stock;';
        $query .= 'SELECT test FROM unknown ORDER BY nothing;';

        $result = mysqli_multi_query($mysqli, $query);
        do {
            try {
                if ($result = mysqli_store_result($mysqli)) {
                    mysqli_free_result($result);
                }

                if (!mysqli_next_result($mysqli)) {
                    break;
                }
            } catch (Throwable) {
                break;
            }
        } while (true);

        $offset++;
        $this->assertSame('mysqli_multi_query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT CURRENT_USER();',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);

        $offset++;
        $this->assertSame('mysqli_next_result', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT email FROM users ORDER BY id;',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);

        $offset++;
        $this->assertSame('mysqli_next_result', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT name FROM products ORDER BY stock;',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);

        $offset++;
        $this->assertSame('mysqli_next_result', $this->storage->offsetGet($offset)->getName());
        $this->assertStringContainsString("Table 'otel_db.unknown' doesn't exist", $this->storage->offsetGet($offset)->getStatus()->getDescription());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT test FROM unknown ORDER BY nothing;',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::EXCEPTION_TYPE => mysqli_sql_exception::class,
        ]);

        mysqli_report(MYSQLI_REPORT_ERROR);

        $result = mysqli_multi_query($mysqli, $query);
        do {
            try {
                if ($result = mysqli_store_result($mysqli)) {
                    mysqli_free_result($result);
                }

                if (!mysqli_next_result($mysqli)) {
                    break;
                }
            } catch (Throwable) {
                break;
            }
        } while (true);

        $offset++;
        $this->assertSame('mysqli_multi_query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT CURRENT_USER();',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);

        $offset++;
        $this->assertSame('mysqli_next_result', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT email FROM users ORDER BY id;',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);

        $offset++;
        $this->assertSame('mysqli_next_result', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT name FROM products ORDER BY stock;',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);

        $offset++;
        $this->assertSame('mysqli_next_result', $this->storage->offsetGet($offset)->getName());
        $this->assertStringContainsString("Table 'otel_db.unknown' doesn't exist", $this->storage->offsetGet($offset)->getStatus()->getDescription());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT test FROM unknown ORDER BY nothing;',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::EXCEPTION_TYPE => \PHPUnit\Framework\Error\Warning::class,
        ]);

        $offset++;
        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_mysqli_prepare_objective(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $mysqli = new mysqli($this->mysqlHost, $this->user, $this->passwd, $this->database);

        $offset = 0;
        $this->assertSame('mysqli::__construct', $this->storage->offsetGet($offset)->getName());

        try {
            $stmt = $mysqli->prepare('SELECT * FROM otel_db.users');

            $offset++;
            $this->assertSame('mysqli::prepare', $this->storage->offsetGet($offset)->getName());
            $this->assertAttributes($offset, [
                TraceAttributes::DB_STATEMENT => 'SELECT * FROM otel_db.users',
                TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            ]);

            $stmt->execute();
            $offset++;

            $this->assertSame('mysqli_stmt::execute', $this->storage->offsetGet($offset)->getName());
            $this->assertAttributes($offset, [
                TraceAttributes::DB_STATEMENT => 'SELECT * FROM otel_db.users',
                TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            ]);

            $stmt->fetch();
            $stmt->close();

        } catch (mysqli_sql_exception $exception) {
            $this->fail('Unexpected exception was thrown: ' . $exception->getMessage());
        }

        try {
            $stmt = $mysqli->prepare('SELECT * FROM unknown_db.users');

            $this->fail('Should never reach this point');
        } catch (mysqli_sql_exception $exception) {
            $offset++;

            $this->assertSame('mysqli::prepare', $this->storage->offsetGet($offset)->getName());
            $this->assertAttributes($offset, [
                TraceAttributes::DB_STATEMENT => 'SELECT * FROM unknown_db.users',
                TraceAttributes::DB_OPERATION_NAME => 'SELECT',
                TraceAttributes::EXCEPTION_TYPE => mysqli_sql_exception::class,
            ]);

        }

        $offset++;
        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_mysqli_prepare_procedural(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR);

        $mysqli = new mysqli($this->mysqlHost, $this->user, $this->passwd, $this->database);

        $offset = 0;
        $this->assertSame('mysqli::__construct', $this->storage->offsetGet($offset)->getName());

        try {
            $stmt = mysqli_prepare($mysqli, 'SELECT * FROM otel_db.users');

            $offset++;
            $this->assertSame('mysqli_prepare', $this->storage->offsetGet($offset)->getName());
            $this->assertAttributes($offset, [
                TraceAttributes::DB_STATEMENT => 'SELECT * FROM otel_db.users',
                TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            ]);

            mysqli_stmt_execute($stmt);
            $offset++;

            $this->assertSame('mysqli_stmt_execute', $this->storage->offsetGet($offset)->getName());
            $this->assertAttributes($offset, [
                TraceAttributes::DB_STATEMENT => 'SELECT * FROM otel_db.users',
                TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            ]);

            mysqli_stmt_fetch($stmt);
            mysqli_stmt_close($stmt);
        } catch (mysqli_sql_exception $exception) {
            $this->fail('Unexpected exception was thrown: ' . $exception->getMessage());
        }

        try {
            $stmt = mysqli_prepare($mysqli, 'SELECT * FROM unknown_db.users');

            $this->fail('Should never reach this point');
        } catch (\Throwable $exception) {
            $offset++;

            $this->assertSame('mysqli_prepare', $this->storage->offsetGet($offset)->getName());
            $this->assertAttributes($offset, [
                TraceAttributes::DB_STATEMENT => 'SELECT * FROM unknown_db.users',
                TraceAttributes::DB_OPERATION_NAME => 'SELECT',
                TraceAttributes::EXCEPTION_TYPE => \PHPUnit\Framework\Error\Warning::class,
            ]);
        }

        $offset++;
        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_mysqli_transaction_rollback_objective(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $mysqli = new mysqli($this->mysqlHost, $this->user, $this->passwd, $this->database);

        $offset = 0;
        $this->assertSame('mysqli::__construct', $this->storage->offsetGet($offset)->getName());

        $mysqli->query('DROP TABLE IF EXISTS language;');
        $offset++;

        $mysqli->query('CREATE TABLE IF NOT EXISTS language ( Code text NOT NULL, Speakers int(11) NOT NULL ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');
        $offset++;

        $mysqli->begin_transaction(name: 'supertransaction');
        $offset++;
        $this->assertSame('mysqli::begin_transaction', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            'db.transaction.name' => 'supertransaction',
        ]);

        try {
            // Insert some values
            $mysqli->query("INSERT INTO language(Code, Speakers) VALUES ('DE', 42000123)");

            $offset++;
            $this->assertSame('mysqli::query', $this->storage->offsetGet($offset)->getName());
            $this->assertAttributes($offset, [
                TraceAttributes::DB_STATEMENT => "INSERT INTO language(Code, Speakers) VALUES ('DE', 42000123)",
                TraceAttributes::DB_OPERATION_NAME => 'INSERT',
            ]);

            // Try to insert invalid values
            $language_code = 'FR';
            $native_speakers = 'Unknown';
            $stmt = $mysqli->prepare('INSERT INTO language(Code, Speakers) VALUES (?,?)');
            $offset++;
            $this->assertSame('mysqli::prepare', $this->storage->offsetGet($offset)->getName());
            $this->assertAttributes($offset, [
                TraceAttributes::DB_STATEMENT => 'INSERT INTO language(Code, Speakers) VALUES (?,?)',
                TraceAttributes::DB_OPERATION_NAME => 'INSERT',
            ]);

            $stmt->bind_param('ss', $language_code, $native_speakers);
            $stmt->execute(); // THROWS HERE

            $this->fail('Should never reach this point');
        } catch (mysqli_sql_exception $exception) {
            $offset++;
            $this->assertSame('mysqli_stmt::execute', $this->storage->offsetGet($offset)->getName());
            $this->assertAttributes($offset, [
                TraceAttributes::DB_STATEMENT => 'INSERT INTO language(Code, Speakers) VALUES (?,?)',
                TraceAttributes::DB_OPERATION_NAME => 'INSERT',
            ]);

            $mysqli->rollback(name: 'supertransaction');
            $offset++;
            $this->assertSame('mysqli::rollback', $this->storage->offsetGet($offset)->getName());
            $this->assertAttributes($offset, [
                'db.transaction.name' => 'supertransaction',
            ]);

        }

        $offset++;
        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_mysqli_transaction_rollback_procedural(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR);

        $mysqli = mysqli_connect($this->mysqlHost, $this->user, $this->passwd, $this->database);

        $offset = 0;
        $this->assertSame('mysqli_connect', $this->storage->offsetGet($offset)->getName());

        mysqli_query($mysqli, 'DROP TABLE IF EXISTS language;');
        $offset++;

        mysqli_query($mysqli, 'CREATE TABLE IF NOT EXISTS language ( Code text NOT NULL, Speakers int(11) NOT NULL ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');
        $offset++;

        mysqli_begin_transaction($mysqli, name: 'supertransaction');
        $offset++;
        $this->assertSame('mysqli_begin_transaction', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            'db.transaction.name' => 'supertransaction',
        ]);

        try {
            // Insert some values
            mysqli_query($mysqli, "INSERT INTO language(Code, Speakers) VALUES ('DE', 76000001)");

            $offset++;
            $this->assertSame('mysqli_query', $this->storage->offsetGet($offset)->getName());
            $this->assertAttributes($offset, [
                TraceAttributes::DB_STATEMENT => "INSERT INTO language(Code, Speakers) VALUES ('DE', 76000001)",
                TraceAttributes::DB_OPERATION_NAME => 'INSERT',
            ]);

            // Try to insert invalid values
            $language_code = 'FR';
            $native_speakers = 'Unknown';
            $stmt = mysqli_prepare($mysqli, 'INSERT INTO language(Code, Speakers) VALUES (?,?)');
            $offset++;
            $this->assertSame('mysqli_prepare', $this->storage->offsetGet($offset)->getName());
            $this->assertAttributes($offset, [
                TraceAttributes::DB_STATEMENT => 'INSERT INTO language(Code, Speakers) VALUES (?,?)',
                TraceAttributes::DB_OPERATION_NAME => 'INSERT',
            ]);

            mysqli_stmt_bind_param($stmt, 'ss', $language_code, $native_speakers);

            try {
                mysqli_stmt_execute($stmt);
            } catch (\PHPUnit\Framework\Error\Warning $e) {
                $offset++;
                $this->assertSame('mysqli_stmt_execute', $this->storage->offsetGet($offset)->getName());
                $this->assertAttributes($offset, [
                    TraceAttributes::DB_STATEMENT => 'INSERT INTO language(Code, Speakers) VALUES (?,?)',
                    TraceAttributes::DB_OPERATION_NAME => 'INSERT',
                ]);

                mysqli_rollback($mysqli, name: 'supertransaction');
                $offset++;
                $this->assertSame('mysqli_rollback', $this->storage->offsetGet($offset)->getName());
                $this->assertAttributes($offset, [
                    'db.transaction.name' => 'supertransaction',
                ]);
            }
        } catch (mysqli_sql_exception $exception) {
            $this->fail('Should never reach this point');
        }

        $offset++;
        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_mysqli_transaction_commit_objective(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $mysqli = new mysqli($this->mysqlHost, $this->user, $this->passwd, $this->database);

        $offset = 0;
        $this->assertSame('mysqli::__construct', $this->storage->offsetGet($offset)->getName());

        $mysqli->query('DROP TABLE IF EXISTS language;');
        $offset++;

        $mysqli->query('CREATE TABLE IF NOT EXISTS language ( Code text NOT NULL, Speakers int(11) NOT NULL ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');
        $offset++;

        $mysqli->begin_transaction(name: 'supertransaction');
        $offset++;
        $this->assertSame('mysqli::begin_transaction', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            'db.transaction.name' => 'supertransaction',
        ]);

        try {
            // Insert some values
            $mysqli->query("INSERT INTO language(Code, Speakers) VALUES ('DE', 76000001)");

            $offset++;
            $this->assertSame('mysqli::query', $this->storage->offsetGet($offset)->getName());
            $this->assertAttributes($offset, [
                TraceAttributes::DB_STATEMENT => "INSERT INTO language(Code, Speakers) VALUES ('DE', 76000001)",
                TraceAttributes::DB_OPERATION_NAME => 'INSERT',
            ]);

            // Try to insert invalid values
            $language_code = 'FR';
            $native_speakers = 66000002;
            $stmt = $mysqli->prepare('INSERT INTO language(Code, Speakers) VALUES (?,?)');

            $offset++;
            $this->assertSame('mysqli::prepare', $this->storage->offsetGet($offset)->getName());
            $this->assertAttributes($offset, [
                TraceAttributes::DB_STATEMENT => 'INSERT INTO language(Code, Speakers) VALUES (?,?)',
                TraceAttributes::DB_OPERATION_NAME => 'INSERT',
            ]);

            $stmt->bind_param('ss', $language_code, $native_speakers);
            $stmt->execute();

            $offset++;
            $this->assertSame('mysqli_stmt::execute', $this->storage->offsetGet($offset)->getName());
            $this->assertAttributes($offset, [
                TraceAttributes::DB_STATEMENT => 'INSERT INTO language(Code, Speakers) VALUES (?,?)',
                TraceAttributes::DB_OPERATION_NAME => 'INSERT',
            ]);

            $mysqli->commit(name: 'supertransaction');

            $offset++;
            $this->assertSame('mysqli::commit', $this->storage->offsetGet($offset)->getName());
            $this->assertAttributes($offset, [
                'db.transaction.name' => 'supertransaction',
            ]);
        } catch (mysqli_sql_exception $exception) {
            $this->fail('Unexpected exception was thrown: ' . $exception->getMessage());
        }

        $offset++;
        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_mysqli_transaction_commit_procedural(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR);

        $mysqli = mysqli_connect($this->mysqlHost, $this->user, $this->passwd, $this->database);

        $offset = 0;
        $this->assertSame('mysqli_connect', $this->storage->offsetGet($offset)->getName());

        mysqli_query($mysqli, 'DROP TABLE IF EXISTS language;');
        $offset++;

        mysqli_query($mysqli, 'CREATE TABLE IF NOT EXISTS language ( Code text NOT NULL, Speakers int(11) NOT NULL ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');
        $offset++;

        mysqli_begin_transaction($mysqli, name: 'supertransaction');
        $offset++;
        $this->assertSame('mysqli_begin_transaction', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            'db.transaction.name' => 'supertransaction',
        ]);

        try {
            // Insert some values
            mysqli_query($mysqli, "INSERT INTO language(Code, Speakers) VALUES ('DE', 76000001)");

            $offset++;
            $this->assertSame('mysqli_query', $this->storage->offsetGet($offset)->getName());
            $this->assertAttributes($offset, [
                TraceAttributes::DB_STATEMENT => "INSERT INTO language(Code, Speakers) VALUES ('DE', 76000001)",
                TraceAttributes::DB_OPERATION_NAME => 'INSERT',
            ]);

            // Try to insert invalid values
            $language_code = 'FR';
            $native_speakers = 66000002;
            $stmt = mysqli_prepare($mysqli, 'INSERT INTO language(Code, Speakers) VALUES (?,?)');
            $offset++;
            $this->assertSame('mysqli_prepare', $this->storage->offsetGet($offset)->getName());
            $this->assertAttributes($offset, [
                TraceAttributes::DB_STATEMENT => 'INSERT INTO language(Code, Speakers) VALUES (?,?)',
                TraceAttributes::DB_OPERATION_NAME => 'INSERT',
            ]);

            mysqli_stmt_bind_param($stmt, 'ss', $language_code, $native_speakers);
            mysqli_stmt_execute($stmt);

            $offset++;
            $this->assertSame('mysqli_stmt_execute', $this->storage->offsetGet($offset)->getName());
            $this->assertAttributes($offset, [
                TraceAttributes::DB_STATEMENT => 'INSERT INTO language(Code, Speakers) VALUES (?,?)',
                TraceAttributes::DB_OPERATION_NAME => 'INSERT',
            ]);

            mysqli_commit($mysqli, name: 'supertransaction');

            $offset++;
            $this->assertSame('mysqli_commit', $this->storage->offsetGet($offset)->getName());
            $this->assertAttributes($offset, [
                'db.transaction.name' => 'supertransaction',
            ]);
        } catch (mysqli_sql_exception $exception) {
            $this->fail('Unexpected exception was thrown: ' . $exception->getMessage());
        }

        $offset++;
        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_mysqli_stmt_execute_objective(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $mysqli = new mysqli($this->mysqlHost, $this->user, $this->passwd, $this->database);

        $offset = 0;
        $this->assertSame('mysqli::__construct', $this->storage->offsetGet($offset)->getName());

        $stmt = new mysqli_stmt($mysqli, "SELECT email FROM users WHERE name='John Doe'");
        $stmt->execute();
        $stmt->fetch();
        $stmt->close();

        $offset++;
        $this->assertSame('mysqli_stmt::execute', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => "SELECT email FROM users WHERE name='John Doe'",
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);

        $stmt = $mysqli->stmt_init();
        $stmt->prepare("SELECT email FROM users WHERE name='John Doe'");

        $stmt->execute();
        $stmt->fetch();
        $stmt->close();

        $offset++;
        $this->assertSame('mysqli_stmt::execute', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => "SELECT email FROM users WHERE name='John Doe'",
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);

        $offset++;
        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_mysqli_stmt_execute_procedural(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $mysqli = new mysqli($this->mysqlHost, $this->user, $this->passwd, $this->database);

        $offset = 0;
        $this->assertSame('mysqli::__construct', $this->storage->offsetGet($offset)->getName());

        $stmt = mysqli_stmt_init($mysqli);
        mysqli_stmt_prepare($stmt, "SELECT email FROM users WHERE name='John Doe'");
        mysqli_stmt_execute($stmt);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        $offset++;
        $this->assertSame('mysqli_stmt_execute', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => "SELECT email FROM users WHERE name='John Doe'",
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);

        $offset++;
        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_mysqli_multiquery_with_calls(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $mysqli = new mysqli($this->mysqlHost, $this->user, $this->passwd, $this->database);

        $offset = 0;
        $this->assertSame('mysqli::__construct', $this->storage->offsetGet($offset)->getName());

        $createProcedureSQL = "
        DROP PROCEDURE IF EXISTS get_message;
        CREATE PROCEDURE get_message()
        BEGIN
            -- first result
            SELECT 'Result 1' AS message;
            -- second result
            SELECT 'Result 2' AS message;
        END;
        ";

        $mysqli->multi_query($createProcedureSQL);

        $offset++;
        $this->assertSame('mysqli::multi_query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'DROP PROCEDURE IF EXISTS get_message;',
            TraceAttributes::DB_OPERATION_NAME => 'DROP',
        ]);

        while ($mysqli->next_result()) {
            if ($result = $mysqli->store_result()) {
                $result->free();
            }
        }

        $offset++;
        $this->assertSame('mysqli::next_result', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_OPERATION_NAME => 'CREATE',
        ]);
        $span = $this->storage->offsetGet($offset);
        $this->assertStringStartsWith('CREATE PROCEDURE', $span->getAttributes()->get(TraceAttributes::DB_STATEMENT));
        $this->assertStringEndsWith('END;', $span->getAttributes()->get(TraceAttributes::DB_STATEMENT));

        $stmt = $mysqli->prepare('CALL get_message();');
        $offset++;
        $this->assertSame('mysqli::prepare', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'CALL get_message();',
            TraceAttributes::DB_OPERATION_NAME => 'CALL',
        ]);

        $stmt->execute();

        $offset++;
        $this->assertSame('mysqli_stmt::execute', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'CALL get_message();',
            TraceAttributes::DB_OPERATION_NAME => 'CALL',
        ]);

        do {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    // echo 'Result: ' . str_replace(PHP_EOL, '', print_r($row, true)) . PHP_EOL;
                }
                $result->free();
            }
        } while ($stmt->next_result());

        $offset++;
        $this->assertSame('mysqli_stmt::next_result', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'CALL get_message();',
            TraceAttributes::DB_OPERATION_NAME => 'CALL',
        ]);

        $offset++;
        $this->assertSame('mysqli_stmt::next_result', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'CALL get_message();',
            TraceAttributes::DB_OPERATION_NAME => 'CALL',
        ]);

        // the same but procedural

        mysqli_stmt_execute($stmt);

        $offset++;
        $this->assertSame('mysqli_stmt_execute', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'CALL get_message();',
            TraceAttributes::DB_OPERATION_NAME => 'CALL',
        ]);

        do {
            $result = mysqli_stmt_get_result($stmt);
            if ($result) {
                while ($row = mysqli_fetch_assoc($result)) {
                    // echo 'Result: ' . str_replace(PHP_EOL, '', print_r($row, true)) . PHP_EOL;
                }
                mysqli_free_result($result);
            }
        } while (mysqli_stmt_next_result($stmt));

        $offset++;
        $this->assertSame('mysqli_stmt_next_result', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'CALL get_message();',
            TraceAttributes::DB_OPERATION_NAME => 'CALL',
        ]);

        $offset++;
        $this->assertSame('mysqli_stmt_next_result', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'CALL get_message();',
            TraceAttributes::DB_OPERATION_NAME => 'CALL',
        ]);

        $offset++;
        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_mysqli_change_user(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR| MYSQLI_REPORT_STRICT);

        $mysqli = new mysqli($this->mysqlHost, $this->user, $this->passwd, $this->database);

        $offset = 0;
        $this->assertSame('mysqli::__construct', $this->storage->offsetGet($offset)->getName());

        $offset++;
        $res = $mysqli->query('SELECT CURRENT_USER();');
        if ($res instanceof mysqli_result) {
            while ($res->fetch_object()) {
            }
        }

        $this->assertSame('mysqli::query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT CURRENT_USER();',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::SERVER_ADDRESS => $this->mysqlHost,
            TraceAttributes::DB_USER => $this->user,
            TraceAttributes::DB_NAMESPACE => $this->database,
            TraceAttributes::DB_SYSTEM => 'mysql',
        ]);

        $mysqli->change_user('otel_user2', $this->passwd, 'otel_db2');

        $offset++;
        $res = $mysqli->query('SELECT CURRENT_USER();');
        if ($res instanceof mysqli_result) {
            while ($res->fetch_object()) {
            }
        }

        $this->assertSame('mysqli::query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT CURRENT_USER();',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::SERVER_ADDRESS => $this->mysqlHost,
            TraceAttributes::DB_USER => 'otel_user2',
            TraceAttributes::DB_NAMESPACE => 'otel_db2',
            TraceAttributes::DB_SYSTEM => 'mysql',
        ]);

        mysqli_change_user($mysqli, $this->user, $this->passwd, $this->database);

        $offset++;
        $res = $mysqli->query('SELECT CURRENT_USER();');
        if ($res instanceof mysqli_result) {
            while ($res->fetch_object()) {
            }
        }

        $this->assertSame('mysqli::query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT CURRENT_USER();',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::SERVER_ADDRESS => $this->mysqlHost,
            TraceAttributes::DB_USER => $this->user,
            TraceAttributes::DB_NAMESPACE => $this->database,
            TraceAttributes::DB_SYSTEM => 'mysql',
        ]);

        try {
            mysqli_change_user($mysqli, 'blahh', $this->passwd, 'unknowndb');
        } catch (Throwable) {
        }

        $offset++;

        try {
            $res = $mysqli->query('SELECT CURRENT_USER();');
            if ($res instanceof mysqli_result) {
                while ($res->fetch_object()) {
                }
            }
        } catch (Throwable) {
        }

        $this->assertSame('mysqli::query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT CURRENT_USER();',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::SERVER_ADDRESS => $this->mysqlHost,
            TraceAttributes::DB_USER => $this->user,
            TraceAttributes::DB_NAMESPACE => $this->database,
            TraceAttributes::DB_SYSTEM => 'mysql',
            TraceAttributes::EXCEPTION_TYPE => mysqli_sql_exception::class,
        ]);

        $offset++;
        $this->assertCount($offset, $this->storage);
    }

    public function test_mysqli_select_db(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR| MYSQLI_REPORT_STRICT);

        $mysqli = new mysqli($this->mysqlHost, $this->user, $this->passwd, $this->database);

        $offset = 0;
        $this->assertSame('mysqli::__construct', $this->storage->offsetGet($offset)->getName());

        $offset++;
        $res = $mysqli->query('SELECT * FROM users;');
        if ($res instanceof mysqli_result) {
            while ($res->fetch_object()) {
            }
        }

        $this->assertSame('mysqli::query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT * FROM users;',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::SERVER_ADDRESS => $this->mysqlHost,
            TraceAttributes::DB_USER => $this->user,
            TraceAttributes::DB_NAMESPACE => $this->database,
            TraceAttributes::DB_SYSTEM => 'mysql',
        ]);

        $mysqli->select_db('otel_db2');

        try {
            $res = $mysqli->query('SELECT * FROM users;');
            $this->fail('Should never reach this point');
        } catch (\Throwable $e) {
            $offset++;

            $this->assertSame('mysqli::query', $this->storage->offsetGet($offset)->getName());
            $this->assertAttributes($offset, [
                TraceAttributes::DB_STATEMENT => 'SELECT * FROM users;',
                TraceAttributes::DB_OPERATION_NAME => 'SELECT',
                TraceAttributes::SERVER_ADDRESS => $this->mysqlHost,
                TraceAttributes::DB_USER => $this->user,
                TraceAttributes::DB_NAMESPACE => 'otel_db2',
                TraceAttributes::DB_SYSTEM => 'mysql',
                TraceAttributes::EXCEPTION_TYPE => mysqli_sql_exception::class,
            ]);
        }

        mysqli_select_db($mysqli, $this->database);

        $res = $mysqli->query('SELECT * FROM users;');
        if ($res instanceof mysqli_result) {
            while ($res->fetch_object()) {
            }
        }

        $offset++;
        $this->assertSame('mysqli::query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT * FROM users;',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::SERVER_ADDRESS => $this->mysqlHost,
            TraceAttributes::DB_USER => $this->user,
            TraceAttributes::DB_NAMESPACE => $this->database,
            TraceAttributes::DB_SYSTEM => 'mysql',
        ]);

        try {
            mysqli_select_db($mysqli, 'unknown');
        } catch (Throwable) {

        }

        $res = $mysqli->query('SELECT * FROM users;');
        if ($res instanceof mysqli_result) {
            while ($res->fetch_object()) {
            }
        }

        $offset++;
        $this->assertSame('mysqli::query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_STATEMENT => 'SELECT * FROM users;',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::SERVER_ADDRESS => $this->mysqlHost,
            TraceAttributes::DB_USER => $this->user,
            TraceAttributes::DB_NAMESPACE => $this->database,
            TraceAttributes::DB_SYSTEM => 'mysql',
        ]);

        $offset++;
        $this->assertCount($offset, $this->storage);
    }

}
