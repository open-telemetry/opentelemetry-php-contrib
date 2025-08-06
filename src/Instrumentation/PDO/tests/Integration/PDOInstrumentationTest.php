<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\PDO\Integration;

use ArrayObject;
use OpenTelemetry\API\Common\Time\TestClock;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Export\InMemoryStorageManager;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\Exemplar\ExemplarFilter\AllExemplarFilter;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\StalenessHandler\DelayedStalenessHandlerFactory;
use OpenTelemetry\SDK\Metrics\View\CriteriaViewRegistry;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\TestUtils\TraceStructureAssertionTrait;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

class PDOInstrumentationTest extends TestCase
{
    use TraceStructureAssertionTrait;

    private ScopeInterface $scope;
    /** @var ArrayObject<array-key, mixed> */
    private ArrayObject $storage;
    private $metricReader;
    private $meterProvider;
    private $metricExporter;

    private function createDB(): PDO
    {
        return new PDO('sqlite::memory:');
    }

    private function createDBWithNewSubclass(): PDO
    {
        if (!class_exists('Pdo\Sqlite')) {
            $this->markTestSkipped('Pdo\Sqlite class is not available in this PHP version');
        }

        /** @psalm-suppress UndefinedMethod */
        return PDO::connect('sqlite::memory:');
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
        $clock = new TestClock();
        $this->metricExporter = new \OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter(
            InMemoryStorageManager::metrics(),
            Temporality::CUMULATIVE
        );
        $this->metricReader = new ExportingReader($this->metricExporter);
        $this->meterProvider = new MeterProvider(
            null,
            ResourceInfoFactory::emptyResource(),
            $clock,
            Attributes::factory(),
            new InstrumentationScopeFactory(Attributes::factory()),
            [$this->metricReader],
            new CriteriaViewRegistry(),
            new AllExemplarFilter(),
            new DelayedStalenessHandlerFactory($clock, 20),
        );

        $this->scope = Configurator::create()
            ->withTracerProvider($tracerProvider)
            ->withMeterProvider($this->meterProvider)
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

    /**
     * @psalm-suppress UndefinedClass
     * @psalm-suppress InvalidClass
     */
    public function test_pdo_sqlite_subclass(): void
    {
        // skip if php version is less than 8.4
        if (version_compare(PHP_VERSION, '8.4', '<')) {
            $this->markTestSkipped('Pdo\Sqlite class is not available in this PHP version');
        }

        $this->assertCount(0, $this->storage);

        /**
         * Need to suppress because of different casing of the class name
         *
         * @psalm-suppress UndefinedClass
         * @psalm-suppress InvalidClass
         * @var Pdo\Sqlite $db
         */
        $db = self::createDBWithNewSubclass();
        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);
        $this->assertSame('PDO::connect', $span->getName());
        $this->assertEquals('sqlite', $span->getAttributes()->get(TraceAttributes::DB_SYSTEM_NAME));

        // Test that the subclass-specific methods work
        $db->createFunction('test_function', static fn ($value) => strtoupper($value));

        // Test that standard PDO operations still work
        $db->exec($this->fillDB());
        $span = $this->storage->offsetGet(1);
        $this->assertSame('PDO::exec', $span->getName());
        $this->assertEquals('sqlite', $span->getAttributes()->get(TraceAttributes::DB_SYSTEM_NAME));
        $this->assertCount(2, $this->storage);

        // Test that the custom function works
        $result = $db->query("SELECT test_function('hello')")->fetchColumn();
        $this->assertEquals('HELLO', $result);
        $span = $this->storage->offsetGet(2);
        $this->assertSame('PDO::query', $span->getName());
        $this->assertEquals('sqlite', $span->getAttributes()->get(TraceAttributes::DB_SYSTEM_NAME));
        $this->assertCount(3, $this->storage);
    }

    public function test_constructor_exception(): void
    {
        $this->expectException(PDOException::class);
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

    public function test_span_hierarchy_with_pdo_operations(): void
    {
        $this->assertCount(0, $this->storage);

        // Create a server span
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage)
            )
        );
        $tracer = $tracerProvider->getTracer('test');
        /** @var SpanInterface $serverSpan */
        $serverSpan = $tracer->spanBuilder('HTTP GET /api/users')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        // Create scope for server span
        $serverScope = Context::storage()->attach($serverSpan->storeInContext(Context::getCurrent()));

        // Create an internal span (simulating business logic)
        /** @var SpanInterface $internalSpan */
        $internalSpan = $tracer->spanBuilder('processUserData')
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->startSpan();

        // Create scope for internal span
        $internalScope = Context::storage()->attach($internalSpan->storeInContext(Context::getCurrent()));

        // Perform PDO operations within the internal span context
        $db = self::createDB();
        $this->assertCount(1, $this->storage); // PDO constructor span

        // Create and populate test table
        $db->exec($this->fillDB());
        $this->assertCount(2, $this->storage); // PDO exec span

        // Query data
        $stmt = $db->prepare('SELECT * FROM technology WHERE name = ?');
        $this->assertCount(3, $this->storage); // PDO prepare span

        $stmt->execute(['PHP']);
        $this->assertCount(4, $this->storage); // PDOStatement execute span

        $result = $stmt->fetchAll();
        $this->assertCount(5, $this->storage); // PDOStatement fetchAll span

        // Verify span hierarchy
        /** @var ImmutableSpan $pdoSpan */
        $pdoSpan = $this->storage->offsetGet(0);
        /** @var ImmutableSpan $execSpan */
        $execSpan = $this->storage->offsetGet(1);
        /** @var ImmutableSpan $prepareSpan */
        $prepareSpan = $this->storage->offsetGet(2);
        /** @var ImmutableSpan $executeSpan */
        $executeSpan = $this->storage->offsetGet(3);
        /** @var ImmutableSpan $fetchAllSpan */
        $fetchAllSpan = $this->storage->offsetGet(4);

        // All PDO spans should be children of the internal span
        $this->assertEquals($internalSpan->getContext()->getSpanId(), $pdoSpan->getParentSpanId());
        $this->assertEquals($internalSpan->getContext()->getSpanId(), $execSpan->getParentSpanId());
        $this->assertEquals($internalSpan->getContext()->getSpanId(), $prepareSpan->getParentSpanId());
        $this->assertEquals($internalSpan->getContext()->getSpanId(), $executeSpan->getParentSpanId());
        $this->assertEquals($internalSpan->getContext()->getSpanId(), $fetchAllSpan->getParentSpanId());

        // Detach scopes
        $internalScope->detach();
        $internalSpan->end();
        $serverScope->detach();
        $serverSpan->end();

        $this->assertTraceStructure(
            $this->storage,
            [
                [
                    'name' => 'HTTP GET /api/users',
                    'kind' => SpanKind::KIND_SERVER,
                    'attributes' => [],
                    'children' => [
                        [
                            'name' => 'processUserData',
                            'kind' => SpanKind::KIND_INTERNAL,
                            'attributes' => [],
                            'children' => [
                                [
                                    'name' => 'PDO::__construct',
                                    'kind' => SpanKind::KIND_CLIENT,
                                    'attributes' => [
                                        TraceAttributes::DB_SYSTEM_NAME => 'sqlite',
                                    ],
                                ],
                                [
                                    'name' => 'PDO::exec',
                                    'kind' => SpanKind::KIND_CLIENT,
                                    'attributes' => [
                                        TraceAttributes::DB_SYSTEM_NAME => 'sqlite',
                                    ],
                                ],
                                [
                                    'name' => 'PDO::prepare',
                                    'kind' => SpanKind::KIND_CLIENT,
                                    'attributes' => [
                                        TraceAttributes::DB_SYSTEM_NAME => 'sqlite',
                                    ],
                                ],
                                [
                                    'name' => 'PDOStatement::execute',
                                    'kind' => SpanKind::KIND_CLIENT,
                                    'attributes' => [
                                        TraceAttributes::DB_SYSTEM_NAME => 'sqlite',
                                    ],
                                ],
                                [
                                    'name' => 'PDOStatement::fetchAll',
                                    'kind' => SpanKind::KIND_CLIENT,
                                    'attributes' => [
                                        TraceAttributes::DB_SYSTEM_NAME => 'sqlite',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );
    }

    public function test_connection_metrics(): void
    {
        $db = self::createDB();
        $db->exec($this->fillDB());

        $non_utf8_id = mb_convert_encoding('rückwärts', 'ISO-8859-1', 'UTF-8');

        $stmt = $db->prepare("SELECT id FROM technology WHERE id = '{$non_utf8_id}'");
        $span_db_prepare = $this->storage->offsetGet(2);
        $this->assertTrue(mb_check_encoding($span_db_prepare->getAttributes()->get(TraceAttributes::DB_QUERY_TEXT), 'UTF-8'));
        $this->assertCount(3, $this->storage);

        $stmt->execute();
        $stmt->fetchAll();
        unset($db);

        $meterProvider = Globals::meterProvider();
        if ($meterProvider instanceof MeterProvider) {
            $meterProvider->forceFlush();
            $meterProvider->shutdown();
        }

        $metricStats = [];
        /** @var Metric $metric */
        foreach (InMemoryStorageManager::metrics() as $metric) {
            $metricStats[$metric->name] ??= [];
            $metricStats[$metric->name][] = $metric->data;
        }
        self::assertCount(2, $metricStats['db.client.connection.count']);
        self::assertCount(2, $metricStats['db.client.connection.pending_requests']);
        self::assertCount(2, $metricStats['db.client.operation.duration']);
        self::assertCount(2, $metricStats['db.client.response.returned_rows']);

        // check count metrics:
        $this->checkMetricSum($metricStats['db.client.connection.count'], 2, 2);
        $this->checkMetricSum($metricStats['db.client.connection.pending_requests'], 0, 2);
        $this->checkMetricDataPointsCount($metricStats['db.client.operation.duration'], 2);
        $this->checkMetricDataPointsCount($metricStats['db.client.response.returned_rows'], 2);
    }

    public function test_error_info_in_silent_mode(): void
    {
        $db = self::createDB();
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $db->exec($this->fillDB());

        $db->query('SELECT id FROM non_existing_table');

        $meterProvider = Globals::meterProvider();
        if ($meterProvider instanceof MeterProvider) {
            $meterProvider->forceFlush();
            $meterProvider->shutdown();
        }
        $metrics = InMemoryStorageManager::metrics();
        self::assertTrue(true);
    }

    public function checkMetricDataPointsCount($metrics, $expectedCount): void
    {
        $sum = 0;
        /** @var Sum $point */
        foreach ($metrics as $point) {
            $sum += $point->dataPoints[0]->count;
        }
        self::assertEquals($expectedCount, $sum);
    }

    public function checkMetricSum($metrics, $expectedSum, ?int $count = null): void
    {
        $sum = 0;
        /** @var Sum $point */
        foreach ($metrics as $point) {
            $sum += $point->dataPoints[0]->value;
        }
        self::assertEquals($expectedSum, $sum);
        if ($count) {
            self::assertCount($count, $metrics);
        }
    }
}
