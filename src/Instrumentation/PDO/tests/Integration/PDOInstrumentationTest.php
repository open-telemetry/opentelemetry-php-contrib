<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\PDO\tests\Integration;

use ArrayObject;
use OpenTelemetry\API\Common\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

class PDOInstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    private ArrayObject $storage;
    private TracerProvider $tracerProvider;

    private function createDB():\PDO
    {
        return new \PDO('sqlite::memory:');
    }

    private function fillDB():string
    {
        $statement =<<<SQL
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

        return $statement;
    }

    public function setUp(): void
    {
        $this->storage = new ArrayObject();
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage)
            )
        );

        $this->scope = Configurator::create()
            ->withTracerProvider($this->tracerProvider)
            ->activate();
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    public function test_pdo_construct(): void
    {
        // @var ImmutableSpan $span
        $this->assertCount(0, $this->storage);
        $pdo =  self::createDB();
        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        $this->assertSame('PDO::__construct', $span->getName());
    }

    public function test_constructor_exception(): void
    {
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('could not find driver');
        new \PDO('unknown:foo');
    }

    public function test_statement_execution(): void
    {
        // @var ImmutableSpan $span
        $db =  self::createDB();
        $statement = self::fillDB();

        $db->exec($statement);
        $span = $this->storage->offsetGet(1);
        $this->assertSame('PDO::exec', $span->getName());

        $this->assertSame($db->inTransaction(), false);
        $this->assertCount(2, $this->storage);

        $sth = $db->prepare('SELECT * FROM `technology`');
        $span = $this->storage->offsetGet(2);
        $this->assertSame('PDO::prepare', $span->getName());
        $this->assertCount(3, $this->storage);
        $sth->execute();
        $span = $this->storage->offsetGet(3);
        $this->assertSame('PDOStatement::execute', $span->getName());
        $this->assertCount(4, $this->storage);

        $result = $sth->fetchAll();
        $span = $this->storage->offsetGet(4);
        $this->assertSame('PDOStatement::fetchAll', $span->getName());
        $this->assertCount(5, $this->storage);

        $db->query('SELECT * FROM `technology`');
        $span = $this->storage->offsetGet(5);
        $this->assertSame('PDO::query', $span->getName());
        $this->assertCount(6, $this->storage);
    }
    public function test_transaction(): void
    {
        $db =  self::createDB();
        $result = $db->beginTransaction();
        $span = $this->storage->offsetGet(1);
        $this->assertSame('PDO::beginTransaction', $span->getName());
        $this->assertCount(2, $this->storage);
        $this->assertSame($result, true);
        $statement = self::fillDB();
        $db->exec($statement);
        $result = $db->commit();
        $span = $this->storage->offsetGet(3);
        $this->assertSame('PDO::commit', $span->getName());
        $this->assertCount(4, $this->storage);
        $this->assertSame($result, true);
        $result = $db->beginTransaction();
        $this->assertSame($result, true);
        $this->assertSame($db->inTransaction(), true);
        $db->exec("INSERT INTO technology(`name`, `date`) VALUES('Java', '1995-05-23');");
        $result = $db->rollback();
        $span = $this->storage->offsetGet(6);
        $this->assertSame('PDO::rollBack', $span->getName());
        $this->assertCount(7, $this->storage);
        $this->assertSame($result, true);
        $this->assertSame($db->inTransaction(), false);
        $sth = $db->prepare('SELECT * FROM `technology`');
        $sth->execute();
        $this->assertSame(count($sth->fetchAll()), 2);
    }
}
