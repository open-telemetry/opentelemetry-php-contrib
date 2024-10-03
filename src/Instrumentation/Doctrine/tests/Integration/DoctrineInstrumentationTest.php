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
        $this->assertEquals('sqlite3', $span->getAttributes()->get(TraceAttributes::DB_SYSTEM));
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

    public function test_statement_execution(): void
    {
        $connection =  self::createConnection();
        $statement = self::fillDB();

        $connection->executeStatement($statement);
        $span = $this->storage->offsetGet(1);
        $this->assertSame('Doctrine\DBAL\Driver\Connection::exec', $span->getName());
        $this->assertFalse($connection->isTransactionActive());
        $this->assertCount(2, $this->storage);

        $connection->prepare('SELECT * FROM `technology`');
        $span = $this->storage->offsetGet(2);
        $this->assertSame('Doctrine\DBAL\Driver\Connection::prepare', $span->getName());
        $this->assertCount(3, $this->storage);

        $connection->executeQuery('SELECT * FROM `technology`');
        $span = $this->storage->offsetGet(3);
        $this->assertSame('Doctrine\DBAL\Driver\Connection::query', $span->getName());
        $this->assertCount(4, $this->storage);
    }

    public function test_transaction(): void
    {
        $connection =  self::createConnection();
        $connection->beginTransaction();
        $span = $this->storage->offsetGet(1);
        $this->assertSame('Doctrine\DBAL\Driver\Connection::beginTransaction', $span->getName());
        $this->assertCount(2, $this->storage);

        $statement = self::fillDB();
        $connection->executeStatement($statement);
        $span = $this->storage->offsetGet(2);
        $this->assertSame('Doctrine\DBAL\Driver\Connection::exec', $span->getName());
        $connection->commit();
        $span = $this->storage->offsetGet(3);
        $this->assertSame('Doctrine\DBAL\Driver\Connection::commit', $span->getName());
        $this->assertCount(4, $this->storage);

        $connection->beginTransaction();
        $this->assertTrue($connection->isTransactionActive());

        $connection->executeStatement("INSERT INTO technology(`name`, `date`) VALUES('Java', '1995-05-23');");
        $connection->rollback();
        $span = $this->storage->offsetGet(6);
        $this->assertSame('Doctrine\DBAL\Driver\Connection::rollBack', $span->getName());
        $this->assertCount(7, $this->storage);
        $this->assertFalse($connection->isTransactionActive());

        $sth = $connection->prepare('SELECT * FROM `technology`');
        $this->assertSame(2, count($sth->executeQuery()->fetchAllAssociative()));
    }
}
