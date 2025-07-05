<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Doctrine\Integration;

use ArrayObject;
use Doctrine\DBAL\DriverManager;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\TraceAttributes;
use PHPUnit\Framework\TestCase;

class DoctrineInstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    /** @var ArrayObject<int, ImmutableSpan> */
    private ArrayObject $storage;

    private function createConnection(): \Doctrine\DBAL\Connection
    {
        $connectionParams = [
            'driver' => 'sqlite3',
            'memory' => true,
        ];

        $conn = DriverManager::getConnection($connectionParams);
        // Trigger internal connect
        $conn->getServerVersion();

        return $conn;
    }

    private function fillDB(): string
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

    public function test_connection(): void
    {
        $this->assertCount(0, $this->storage);
        $conn = self::createConnection();
        $this->assertCount(1, $this->storage);
        $this->assertTrue($conn->isConnected());
        $span = $this->storage->offsetGet(0);
        $this->assertSame('Doctrine\DBAL\Driver::connect', $span->getName());
        $this->assertEquals('sqlite', $span->getAttributes()->get(TraceAttributes::DB_SYSTEM_NAME));
    }

    public function test_connection_exception(): void
    {
        $this->expectException(\Doctrine\DBAL\Exception::class);
        $this->expectExceptionMessageMatches('/The given driver "unknown" is unknown/');
        /**
         * @psalm-suppress InvalidArgument
         * @phpstan-ignore argument.type
        */
        DriverManager::getConnection([
            'driver' => 'unknown',
        ]);
    }

    public function test_connection_execute_statement(): void
    {
        $connection =  self::createConnection();
        $statement = self::fillDB();

        $connection->executeStatement($statement);
        $span = $this->storage->offsetGet(1);
        $this->assertSame('CREATE technology', $span->getName());
        $this->assertFalse($connection->isTransactionActive());
        $this->assertCount(2, $this->storage);

        $connection->prepare('SELECT * FROM `technology`');
        $span = $this->storage->offsetGet(2);
        $this->assertSame('SELECT technology', $span->getName());
        $this->assertSame('prepare', $span->getAttributes()->get(TraceAttributes::DB_OPERATION_NAME));

        $connection->executeQuery('SELECT * FROM `technology`');
        $span = $this->storage->offsetGet(3);
        $this->assertSame('SELECT technology', $span->getName());
        $this->assertSame('SELECT', $span->getAttributes()->get(TraceAttributes::DB_OPERATION_NAME));
        $this->assertCount(4, $this->storage);
    }

    public function test_prepare_then_execute_statement(): void
    {
        $connection =  self::createConnection();
        $statement = self::fillDB();
        $connection->executeStatement($statement);

        $stmt = $connection->prepare('SELECT * FROM `technology` WHERE name = :name');
        $prepare = $this->storage->offsetGet(2);
        $this->assertSame('prepare', $prepare->getAttributes()->get(TraceAttributes::DB_OPERATION_NAME));
        $this->assertSame('SELECT technology', $prepare->getName());

        $stmt->bindValue('name', 'PHP');
        $stmt->executeQuery();
        $execute = $this->storage->offsetGet(3);
        $this->assertSame('Doctrine::execute', $execute->getName());
        $this->assertSame('execute', $execute->getAttributes()->get(TraceAttributes::DB_OPERATION_NAME));
        $this->assertCount(1, $execute->getLinks());
        $this->assertEquals($prepare->getContext(), $execute->getLinks()[0]->getSpanContext(), 'execute span is linked to prepare span');
    }

    public function test_tracked_span_context_when_prepare_span_flushed(): void
    {
        $connection =  self::createConnection();
        $statement = self::fillDB();
        $connection->executeStatement($statement);

        $stmt = $connection->prepare('SELECT * FROM `technology` WHERE name = :name');
        $this->storage->exchangeArray([]); //removes the reference to prepared span, including the tracked SpanContext
        $stmt->bindValue('name', 'PHP');
        $stmt->executeQuery();

        $execute = $this->storage->offsetGet(0);
        $this->assertCount(1, $execute->getLinks());
    }

    public function test_query_with_bind_variables(): void
    {
        $connection =  self::createConnection();
        $statement = self::fillDB();
        $connection->executeStatement($statement);

        $connection->executeQuery('SELECT * FROM `technology` WHERE name = :name', ['name' => 'PHP']);
        $prepare = $this->storage->offsetGet(2);
        $this->assertSame('SELECT technology', $prepare->getName());
        $execute = $this->storage->offsetGet(3);
        $this->assertSame('Doctrine::execute', $execute->getName());
        $this->assertCount(1, $execute->getLinks());
        $this->assertEquals($prepare->getContext(), $execute->getLinks()[0]->getSpanContext(), 'execute span is linked to prepare span');
        $this->assertCount(4, $this->storage);
    }

    public function test_transaction(): void
    {
        $connection =  self::createConnection();
        $connection->beginTransaction();
        $span = $this->storage->offsetGet(1);
        $this->assertSame('Doctrine::beginTransaction', $span->getName());
        $this->assertSame('begin', $span->getAttributes()->get(TraceAttributes::DB_OPERATION_NAME));
        $this->assertCount(2, $this->storage);

        $statement = self::fillDB();
        $connection->executeStatement($statement);
        $span = $this->storage->offsetGet(2);
        $this->assertSame('CREATE technology', $span->getName());
        $connection->commit();
        $span = $this->storage->offsetGet(3);
        $this->assertSame('Doctrine::commit', $span->getName());
        $this->assertSame('commit', $span->getAttributes()->get(TraceAttributes::DB_OPERATION_NAME));
        $this->assertCount(4, $this->storage);

        $connection->beginTransaction();
        $this->assertTrue($connection->isTransactionActive());

        $connection->executeStatement("INSERT INTO technology(`name`, `date`) VALUES('Java', '1995-05-23');");
        $connection->rollback();
        $span = $this->storage->offsetGet(6);
        $this->assertSame('Doctrine::rollBack', $span->getName());
        $this->assertSame('rollback', $span->getAttributes()->get(TraceAttributes::DB_OPERATION_NAME));
        $this->assertCount(7, $this->storage);
        $this->assertFalse($connection->isTransactionActive());

        $sth = $connection->prepare('SELECT * FROM `technology`');
        $this->assertSame(2, count($sth->executeQuery()->fetchAllAssociative()));
    }

    public function test_statement_execute(): void
    {
        $connection =  self::createConnection();
        $statement = self::fillDB();
        $connection->executeStatement($statement);
        $stmt = $connection->prepare('SELECT * FROM `technology`');
        $this->storage->exchangeArray([]);
        $stmt->executeQuery();
        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        $this->assertSame('Doctrine::execute', $span->getName());
        $this->assertSame('execute', $span->getAttributes()->get(TraceAttributes::DB_OPERATION_NAME));
    }

    public function test_statement_execute_error(): void
    {
        $connection =  self::createConnection();
        $statement = self::fillDB();
        $connection->executeStatement($statement);
        $stmt = $connection->prepare('insert into technology(name, date) values (?, ?);');
        $this->storage->exchangeArray([]);
        $e = null;

        try {
            $stmt->executeQuery();
        } catch (\Throwable $e) {
            // do nothing
        }
        $this->assertNotNull($e);
        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        $this->assertSame('Error', $span->getStatus()->getCode());
        $this->assertStringContainsString('Unable to execute', $span->getStatus()->getDescription());
    }
}
