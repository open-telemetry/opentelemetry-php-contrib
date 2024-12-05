<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\MySqli\Integration;

use ArrayObject;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;
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

        $this->mysqlHost = getenv('MYSQL_HOST') ?: 'localhost';

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

}
