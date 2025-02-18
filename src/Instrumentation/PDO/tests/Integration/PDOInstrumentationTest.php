<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\PDO\Integration;

use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\TraceAttributes;
use PDO;
use PHPUnit\Framework\TestCase;

class PDOInstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    /** @var ArrayObject<int, ImmutableSpan> */
    private ArrayObject $storage;

    private function createDB(): PDO
    {
        return new PDO('sqlite::memory:');
    }

    private function fillDB():string
    {
        return <<<SQL
        CREATE TABLE `technology` (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(25) NOT NULL,
            date DATE NOT NULL
        );

        INSERT INTO technology(`name`, `date`)
        VALUES
            ('PHP', '1993-04-05'),
            ('CPP', '1979-05-06');

        SQL;
    }

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
            ->activate();
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    public function test_pdo_construct(): void
    {
        $this->assertCount(0, $this->storage);
        self::createDB();
        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        $this->assertSame('PDO::__construct', $span->getName());
        $this->assertEquals('sqlite', $span->getAttributes()->get(TraceAttributes::DB_SYSTEM_NAME));
    }

    public function test_constructor_exception(): void
    {
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('could not find driver');
        new PDO('unknown:foo');
    }

    public function test_statement_execution(): void
    {
        $db =  self::createDB();
        $statement = self::fillDB();

        $db->exec($statement);
        $span = $this->storage->offsetGet(1);
        $this->assertSame('PDO::exec', $span->getName());
        $this->assertEquals('sqlite', $span->getAttributes()->get(TraceAttributes::DB_SYSTEM_NAME));
        $this->assertFalse($db->inTransaction());
        $this->assertCount(2, $this->storage);

        $sth = $db->prepare('SELECT * FROM `technology`');
        $span = $this->storage->offsetGet(2);
        $this->assertSame('PDO::prepare', $span->getName());
        $this->assertEquals('sqlite', $span->getAttributes()->get(TraceAttributes::DB_SYSTEM_NAME));
        $this->assertCount(3, $this->storage);

        $sth->execute();
        $span = $this->storage->offsetGet(3);
        $this->assertSame('PDOStatement::execute', $span->getName());
        $this->assertEquals('sqlite', $span->getAttributes()->get(TraceAttributes::DB_SYSTEM_NAME));
        $this->assertCount(4, $this->storage);

        $sth->fetchAll();
        $span = $this->storage->offsetGet(4);
        $this->assertSame('PDOStatement::fetchAll', $span->getName());
        $this->assertEquals('sqlite', $span->getAttributes()->get(TraceAttributes::DB_SYSTEM_NAME));
        $this->assertCount(5, $this->storage);

        $db->query('SELECT * FROM `technology`');
        $span = $this->storage->offsetGet(5);
        $this->assertSame('PDO::query', $span->getName());
        $this->assertEquals('sqlite', $span->getAttributes()->get(TraceAttributes::DB_SYSTEM_NAME));
        $this->assertCount(6, $this->storage);
    }

    public function test_transaction(): void
    {
        $db =  self::createDB();
        $result = $db->beginTransaction();
        $span = $this->storage->offsetGet(1);
        $this->assertSame('PDO::beginTransaction', $span->getName());
        $this->assertEquals('sqlite', $span->getAttributes()->get(TraceAttributes::DB_SYSTEM_NAME));
        $this->assertCount(2, $this->storage);
        $this->assertSame($result, true);

        $statement = self::fillDB();
        $db->exec($statement);
        $result = $db->commit();
        $span = $this->storage->offsetGet(3);
        $this->assertSame('PDO::commit', $span->getName());
        $this->assertEquals('sqlite', $span->getAttributes()->get(TraceAttributes::DB_SYSTEM_NAME));
        $this->assertCount(4, $this->storage);
        $this->assertTrue($result);

        $result = $db->beginTransaction();
        $this->assertTrue($result);
        $this->assertTrue($db->inTransaction());

        $db->exec("INSERT INTO technology(`name`, `date`) VALUES('Java', '1995-05-23');");
        $result = $db->rollback();
        $span = $this->storage->offsetGet(6);
        $this->assertSame('PDO::rollBack', $span->getName());
        $this->assertEquals('sqlite', $span->getAttributes()->get(TraceAttributes::DB_SYSTEM_NAME));
        $this->assertCount(7, $this->storage);
        $this->assertTrue($result);
        $this->assertFalse($db->inTransaction());

        $sth = $db->prepare('SELECT * FROM `technology`');
        $sth->execute();
        $this->assertSame(2, count($sth->fetchAll()));
    }

    public function test_execute_and_fetch_all_spans_linked_to_prepared_statement_span(): void
    {
        //setup
        $db = self::createDB();
        $this->assertCount(1, $this->storage, 'pdo constructor span');
        $db->exec($this->fillDB());
        $this->assertCount(2, $this->storage, 'pdo exec span');

        //prepare query
        $stmt = $db->prepare('select * from `technology`');
        $this->assertCount(3, $this->storage, 'statement prepare span');
        $prepareSpan = $this->storage->offsetGet(2);
        $this->assertSame('PDO::prepare', $prepareSpan->getName());

        //execute prepared query
        $stmt->execute();
        $this->assertCount(4, $this->storage, 'statement execute span');
        $executeSpan = $this->storage->offsetGet(3);
        $this->assertSame('PDOStatement::execute', $executeSpan->getName());

        //verify prepared statement linked to execute
        $this->assertCount(1, $executeSpan->getLinks());
        $this->assertEquals($prepareSpan->getContext(), $executeSpan->getLinks()[0]->getSpanContext());

        //fetch prepared query
        $this->assertSame(2, count($stmt->fetchAll()));
        $this->assertCount(5, $this->storage, 'statement fetchAll span');
        $fetchAllSpan = $this->storage->offsetGet(4);
        $this->assertSame('PDOStatement::fetchAll', $fetchAllSpan->getName());

        //verify prepared statement linked to fetchAll
        $this->assertCount(1, $fetchAllSpan->getLinks());
        $this->assertEquals($prepareSpan->getContext(), $fetchAllSpan->getLinks()[0]->getSpanContext());
    }

    /**
     * Tests that the db statement is encoded as UTF-8, this is relevant for grpc exporter which expects UTF-8 encoding.
     */
    public function test_encode_db_statement_as_utf8(): void
    {
        //setup
        $db = self::createDB();
        $db->exec($this->fillDB());

        $non_utf8_id = mb_convert_encoding('rückwärts', 'ISO-8859-1', 'UTF-8');

        $db->prepare("SELECT id FROM technology WHERE id = '{$non_utf8_id}'");
        $span_db_prepare = $this->storage->offsetGet(2);
        $this->assertTrue(mb_check_encoding($span_db_prepare->getAttributes()->get(TraceAttributes::DB_QUERY_TEXT), 'UTF-8'));
        $this->assertCount(3, $this->storage);

        $db->query("SELECT id FROM technology WHERE id = '{$non_utf8_id}'");
        $span_db_query = $this->storage->offsetGet(3);
        $this->assertTrue(mb_check_encoding($span_db_query->getAttributes()->get(TraceAttributes::DB_QUERY_TEXT), 'UTF-8'));
        $this->assertCount(4, $this->storage);

        $db->exec("SELECT id FROM technology WHERE id = '{$non_utf8_id}'");
        $span_db_exec = $this->storage->offsetGet(4);
        $this->assertTrue(mb_check_encoding($span_db_exec->getAttributes()->get(TraceAttributes::DB_QUERY_TEXT), 'UTF-8'));
        $this->assertCount(5, $this->storage);
    }
}
