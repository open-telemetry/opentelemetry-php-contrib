<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\PDO\tests\Integration;

use ArrayObject;
use Mockery;
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

    private function createDB():\PDO {
        return new \PDO('sqlite::memory:');
    }

    private function fillDB():string {
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
        // @var ImmutableSpan $span
        $this->assertCount(0, $this->storage);
        $pdo =  self::createDB();
        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        $this->assertSame('PDO::__construct', $span->getName());
    }

    public function test_statement_execution(): void
    {
        // @var ImmutableSpan $span
        $db =  self::createDB();
        $statement = self::fillDB();

        $db->exec($statement);
        $this->assertCount(2, $this->storage);

        $sth = $db->prepare('SELECT * FROM `technology`');
        $sth->execute();
        $this->assertCount(3, $this->storage);

        $result = $sth->fetchAll();
        $this->assertCount(4, $this->storage);
    }

}
