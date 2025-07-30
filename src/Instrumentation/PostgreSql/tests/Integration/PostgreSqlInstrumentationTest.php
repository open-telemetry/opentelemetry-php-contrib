<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\PostgreSql\Integration;

use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\TraceAttributes;
use PgSql\Connection;
use PgSql\Lob;
use PgSql\Result;
use PHPUnit\Framework\TestCase;

class PostgreSqlInstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    /** @var ArrayObject<int, ImmutableSpan> */
    private ArrayObject $storage;

    private string $pgsqlHost;

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

        $this->pgsqlHost = getenv('POSTGRESQL_HOST') ?: '127.0.0.1';

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
        $this->assertEquals($this->pgsqlHost, $span->getAttributes()->get(TraceAttributes::SERVER_ADDRESS));
        $this->assertEquals($this->database, $span->getAttributes()->get(TraceAttributes::DB_NAMESPACE));
        $this->assertEquals('postgresql', $span->getAttributes()->get(TraceAttributes::DB_SYSTEM_NAME));
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
    public function test_pg_connect(): void
    {
        $conn = pg_connect('host=' . $this->pgsqlHost . ' dbname=' . $this->database . ' user=' . $this->user . ' password=' . $this->passwd);
        $this->assertInstanceOf(Connection::class, $conn);
        pg_close($conn);
        $conn = pg_pconnect('host=' . $this->pgsqlHost . ' dbname=' . $this->database . ' user=' . $this->user . ' password=' . $this->passwd);
        $this->assertInstanceOf(Connection::class, $conn);
        pg_close($conn);

        $offset = 0;
        $this->assertSame('pg_connect', $this->storage->offsetGet($offset++)->getName());
        $this->assertSame('pg_pconnect', $this->storage->offsetGet($offset++)->getName());

        $this->assertCount($offset, $this->storage);

        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_pg_query(): void
    {
        $conn = pg_connect('host=' . $this->pgsqlHost . ' dbname=' . $this->database . ' user=' . $this->user . ' password=' . $this->passwd);
        $this->assertTrue($conn instanceof Connection);

        $offset = 0;
        $this->assertSame('pg_connect', $this->storage->offsetGet($offset)->getName());
        $offset++;

        $res = pg_query($conn, 'SELECT * FROM users');
        $this->assertTrue($res instanceof Result);

        $this->assertSame('pg_query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_QUERY_TEXT => 'SELECT * FROM users',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);

        while ($row = pg_fetch_assoc($res)) {
        }
        $offset++;

        pg_close($conn);

        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_pg_convert(): void
    {
        $conn = pg_connect('host=' . $this->pgsqlHost . ' dbname=' . $this->database . ' user=' . $this->user . ' password=' . $this->passwd);
        $this->assertInstanceOf(Connection::class, $conn);

        $offset = 0;
        $this->assertSame('pg_connect', $this->storage->offsetGet($offset)->getName());
        $offset++;

        $data = [
            'name' => 'Foo Bar',
            'email' => 'foo.bar@example.com',
            'created_at' => '2025-01-01 12:00:00',
        ];

        $converted = pg_convert($conn, 'users', $data);
        $this->assertIsArray($converted);
        // no span from success operation - we're captuing failures only for pg_convert

        $data = [
            'data' => 'data',
        ];

        $converted = @pg_convert($conn, 'users', $data);
        $this->assertFalse($converted);

        $this->assertSame('pg_convert', actual: $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_COLLECTION_NAME => 'users',
        ]);
        $this->assertSame(StatusCode::STATUS_ERROR, $this->storage->offsetGet($offset)->getStatus()->getCode());

        $offset++;

        pg_close($conn);

        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_pg_copy_from(): void
    {
        $conn = pg_connect('host=' . $this->pgsqlHost . ' dbname=' . $this->database . ' user=' . $this->user . ' password=' . $this->passwd);
        $this->assertInstanceOf(Connection::class, $conn);

        $offset = 0;
        $this->assertSame('pg_connect', $this->storage->offsetGet($offset)->getName());
        $offset++;

        $data = [
            "2000\tAlice Test\talice2000@example.com\t2025-07-01 10:00:00",
            "2001\tBob Test\tbob2001@example.com\t2025-07-01 10:05:00",
        ];

        $result = pg_copy_from($conn, 'users', $data, "\t");
        $this->assertTrue($result);

        $this->assertSame('pg_copy_from', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_COLLECTION_NAME => 'users',
        ]);
        $offset++;

        $del = pg_query($conn, 'DELETE FROM users WHERE id IN (2000, 2001)');
        $this->assertTrue($del !== false);

        $this->assertSame('pg_query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_QUERY_TEXT => 'DELETE FROM users WHERE id IN (2000, 2001)',
            TraceAttributes::DB_OPERATION_NAME => 'DELETE',
        ]);
        $offset++;

        pg_close($conn);

        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_pg_copy_to(): void
    {
        $conn = pg_connect('host=' . $this->pgsqlHost . ' dbname=' . $this->database . ' user=' . $this->user . ' password=' . $this->passwd);
        $this->assertInstanceOf(Connection::class, $conn);

        $offset = 0;
        $this->assertSame('pg_connect', $this->storage->offsetGet($offset)->getName());
        $offset++;

        $rows = pg_copy_to($conn, 'users', "\t");
        $this->assertIsArray($rows);
        $this->assertNotEmpty($rows);

        $this->assertSame('pg_copy_to', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_COLLECTION_NAME => 'users',
        ]);
        $offset++;

        pg_close($conn);

        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_pg_delete(): void
    {
        $conn = pg_connect('host=' . $this->pgsqlHost . ' dbname=' . $this->database . ' user=' . $this->user . ' password=' . $this->passwd);
        $this->assertInstanceOf(Connection::class, $conn);

        $offset = 0;
        $this->assertSame('pg_connect', $this->storage->offsetGet($offset)->getName());
        $offset++;

        // Insert test row to delete
        $res = pg_query($conn, "INSERT INTO users (id, name, email, created_at) VALUES (3000, 'Delete Me', 'delete@example.com', now())");
        $this->assertTrue($res !== false);

        $this->assertSame('pg_query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_OPERATION_NAME => 'INSERT',
        ]);
        $offset++;

        // pg_delete uses associative condition array
        $res = pg_delete($conn, 'users', ['id' => 3000]);
        $this->assertTrue($res !== false);

        $this->assertSame('pg_delete', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_OPERATION_NAME => 'DELETE',
            TraceAttributes::DB_COLLECTION_NAME => 'users',
        ]);
        $offset++;

        pg_close($conn);

        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_pg_prepare_and_execute(): void
    {
        $conn = pg_connect('host=' . $this->pgsqlHost . ' dbname=' . $this->database . ' user=' . $this->user . ' password=' . $this->passwd);
        $this->assertInstanceOf(Connection::class, $conn);

        $offset = 0;
        $this->assertSame('pg_connect', $this->storage->offsetGet($offset)->getName());
        $offset++;

        $prepare = pg_prepare($conn, 'select_user_stmt', 'SELECT * FROM users WHERE email = $1');
        $this->assertInstanceOf(Result::class, $prepare);

        $this->assertSame('pg_prepare', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::DB_QUERY_TEXT => 'SELECT * FROM users WHERE email = $1',
        ]);
        $offset++;

        $execute = pg_execute($conn, 'select_user_stmt', ['john.doe@example.com']);
        $this->assertInstanceOf(Result::class, $execute);

        $this->assertSame('pg_execute', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::DB_QUERY_TEXT => 'SELECT * FROM users WHERE email = $1',
        ]);
        $offset++;

        $row = pg_fetch_assoc($execute);
        $this->assertNotEmpty($row);
        $this->assertSame('John Doe', $row['name']);

        pg_close($conn);

        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_pg_select(): void
    {
        $conn = pg_connect('host=' . $this->pgsqlHost . ' dbname=' . $this->database . ' user=' . $this->user . ' password=' . $this->passwd);
        $this->assertInstanceOf(Connection::class, $conn);

        $offset = 0;
        $this->assertSame('pg_connect', $this->storage->offsetGet($offset)->getName());
        $offset++;

        $conditions = [
            'name' => 'Jane Smith',
            'email' => 'jane.smith@example.com',
        ];
        $result = pg_select($conn, 'users', $conditions);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);

        $this->assertSame('pg_select', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::DB_COLLECTION_NAME => 'users',
            TraceAttributes::DB_QUERY_TEXT => "SELECT * FROM users WHERE name = 'Jane Smith' AND email = 'jane.smith@example.com'",
        ]);
        $offset++;

        pg_close($conn);

        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_pg_send_prepare_and_execute(): void
    {
        $conn = pg_connect('host=' . $this->pgsqlHost . ' dbname=' . $this->database . ' user=' . $this->user . ' password=' . $this->passwd);
        $this->assertInstanceOf(Connection::class, $conn);

        $offset = 0;
        $this->assertSame('pg_connect', $this->storage->offsetGet($offset)->getName());
        $offset++;

        $sent = pg_send_prepare($conn, 'async_select_user', 'SELECT * FROM users WHERE email = $1');
        $this->assertTrue($sent);

        $this->assertSame('pg_send_prepare', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::DB_QUERY_TEXT => 'SELECT * FROM users WHERE email = $1',
        ]);
        $offset++;

        $prepareResult = pg_get_result($conn);
        $this->assertInstanceOf(Result::class, $prepareResult);
        $this->assertSame(PGSQL_COMMAND_OK, pg_result_status($prepareResult));

        $this->assertSame('pg_get_result', $this->storage->offsetGet($offset)->getName());
        $offset++;

        $sent = pg_send_execute($conn, 'async_select_user', ['bob.johnson@example.com']);
        $this->assertTrue($sent);

        $this->assertSame('pg_send_execute', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
            TraceAttributes::DB_QUERY_TEXT => 'SELECT * FROM users WHERE email = $1',
        ]);
        $offset++;

        $executeResult = pg_get_result($conn);
        $this->assertInstanceOf(Result::class, $executeResult);

        $this->assertSame('pg_get_result', $this->storage->offsetGet($offset)->getName());
        $offset++;

        $row = pg_fetch_assoc($executeResult);
        $this->assertIsArray($row);
        $this->assertSame('Bob Johnson', $row['name']);

        pg_close($conn);

        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_pg_send_query(): void
    {
        $conn = pg_connect('host=' . $this->pgsqlHost . ' dbname=' . $this->database . ' user=' . $this->user . ' password=' . $this->passwd);
        $this->assertInstanceOf(Connection::class, $conn);

        $offset = 0;
        $this->assertSame('pg_connect', $this->storage->offsetGet($offset)->getName());
        $offset++;

        $sent = pg_send_query($conn, 'SELECT name FROM users WHERE email = \'john.doe@example.com\'');
        $this->assertTrue($sent);

        $this->assertSame('pg_send_query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_QUERY_TEXT => 'SELECT name FROM users WHERE email = \'john.doe@example.com\'',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);
        $offset++;

        $result = pg_get_result($conn);
        $this->assertInstanceOf(Result::class, $result);

        $this->assertSame('pg_get_result', $this->storage->offsetGet($offset)->getName());
        $offset++;

        $row = pg_fetch_assoc($result);
        $this->assertNotEmpty($row);
        $this->assertSame('John Doe', $row['name']);

        pg_close($conn);

        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_pg_send_query_params(): void
    {
        $conn = pg_connect('host=' . $this->pgsqlHost . ' dbname=' . $this->database . ' user=' . $this->user . ' password=' . $this->passwd);
        $this->assertInstanceOf(Connection::class, $conn);

        $offset = 0;
        $this->assertSame('pg_connect', $this->storage->offsetGet($offset)->getName());
        $offset++;

        $sent = pg_send_query_params(
            $conn,
            'SELECT name FROM users WHERE email = $1',
            ['jane.smith@example.com']
        );
        $this->assertTrue($sent);

        $this->assertSame('pg_send_query_params', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_QUERY_TEXT => 'SELECT name FROM users WHERE email = $1',
            TraceAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);
        $offset++;

        $result = pg_get_result($conn);
        $this->assertInstanceOf(Result::class, $result);

        $this->assertSame('pg_get_result', $this->storage->offsetGet($offset)->getName());
        $offset++;

        $row = pg_fetch_assoc($result);
        $this->assertNotEmpty($row);
        $this->assertSame('Jane Smith', $row['name']);

        pg_close($conn);

        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_pg_lo_read_write_unlink(): void
    {
        $conn = pg_connect('host=' . $this->pgsqlHost . ' dbname=' . $this->database . ' user=' . $this->user . ' password=' . $this->passwd);
        $this->assertInstanceOf(Connection::class, $conn);

        $offset = 0;
        $this->assertSame('pg_connect', $this->storage->offsetGet($offset)->getName());
        $offset++;

        // BEGIN
        pg_query($conn, 'BEGIN');
        $this->assertSame('pg_query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_QUERY_TEXT => 'BEGIN',
            TraceAttributes::DB_OPERATION_NAME => 'BEGIN',
        ]);
        $offset++;

        // Create new large object
        $oid = pg_lo_create($conn);
        $this->assertIsInt($oid);

        $fd = pg_lo_open($conn, $oid, 'w');
        $this->assertInstanceOf(Lob::class, $fd);
        $this->assertSame('pg_lo_open', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_OPERATION_NAME => 'OPEN',
        ]);
        $offset++;

        $written = pg_lo_write($fd, "Hello Postgres LOB\n");
        $this->assertGreaterThan(0, $written);
        $this->assertSame('pg_lo_write', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_OPERATION_NAME => 'WRITE',
            'db.postgres.bytes_written' => 19,
        ]);
        $offset++;

        pg_lo_seek($fd, 0, SEEK_SET);
        $data = pg_lo_read($fd, 1024);
        $this->assertIsString($data);
        $this->assertSame("Hello Postgres LOB\n", $data);
        $this->assertSame('pg_lo_read', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_OPERATION_NAME => 'READ',
        ]);
        $offset++;

        pg_lo_seek($fd, 0, SEEK_SET);
        ob_start();
        $readBytes = pg_lo_read_all($fd);
        $output = ob_get_clean();
        $this->assertSame("Hello Postgres LOB\n", $output);
        $this->assertSame('pg_lo_read_all', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_OPERATION_NAME => 'READ',
            'db.postgres.bytes_read' => 19,
        ]);
        $offset++;

        pg_lo_close($fd);

        $this->assertTrue(pg_lo_unlink($conn, $oid));
        $this->assertSame('pg_lo_unlink', $this->storage->offsetGet($offset)->getName());
        $offset++;

        pg_query($conn, 'COMMIT');
        $this->assertSame('pg_query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_QUERY_TEXT => 'COMMIT',
            TraceAttributes::DB_OPERATION_NAME => 'COMMIT',
        ]);
        $offset++;

        pg_close($conn);

        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

    public function test_pg_lo_import_and_export(): void
    {
        $conn = pg_connect('host=' . $this->pgsqlHost . ' dbname=' . $this->database . ' user=' . $this->user . ' password=' . $this->passwd);
        $this->assertInstanceOf(Connection::class, $conn);

        $offset = 0;
        $this->assertSame('pg_connect', $this->storage->offsetGet($offset)->getName());
        $offset++;

        pg_query($conn, 'BEGIN');
        $this->assertSame('pg_query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_QUERY_TEXT => 'BEGIN',
            TraceAttributes::DB_OPERATION_NAME => 'BEGIN',
        ]);
        $offset++;

        $inputPath = tempnam(sys_get_temp_dir(), 'pg-in-');
        $this->assertIsString($inputPath);
        $expectedContent = "LOB FILE CONTENT\n";
        file_put_contents($inputPath, $expectedContent);

        $oid = pg_lo_import($conn, $inputPath);
        $this->assertIsInt($oid);
        $this->assertSame('pg_lo_import', $this->storage->offsetGet($offset)->getName());
        $offset++;

        $outputPath = tempnam(sys_get_temp_dir(), 'pg-out-');
        $this->assertIsString($outputPath);
        $this->assertTrue(pg_lo_export($conn, $oid, $outputPath));
        $this->assertSame('pg_lo_export', $this->storage->offsetGet($offset)->getName());
        $offset++;

        $actualContent = file_get_contents($outputPath);
        $this->assertSame($expectedContent, $actualContent);

        $this->assertTrue(pg_lo_unlink($conn, $oid));
        $this->assertSame('pg_lo_unlink', $this->storage->offsetGet($offset)->getName());
        $offset++;

        pg_query($conn, 'COMMIT');
        $this->assertSame('pg_query', $this->storage->offsetGet($offset)->getName());
        $this->assertAttributes($offset, [
            TraceAttributes::DB_QUERY_TEXT => 'COMMIT',
            TraceAttributes::DB_OPERATION_NAME => 'COMMIT',
        ]);
        $offset++;

        @unlink($inputPath);
        @unlink($outputPath);
        pg_close($conn);

        $this->assertCount($offset, $this->storage);
        $this->assertDatabaseAttributesForAllSpans($offset);
    }

}
