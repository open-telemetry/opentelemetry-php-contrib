<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\MongoDB\tests\Integration;

use ArrayObject;
use MongoDB\Driver\Manager;
use MongoDB\Operation\FindOne;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Contrib\Instrumentation\MongoDB\MongoDBTraceAttributes;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\TraceAttributes;
use PHPUnit\Framework\TestCase;

class MongoDBInstrumentationTest extends TestCase
{
    private const DATABASE_NAME = 'db';
    private const COLLECTION_NAME = 'coll';
    private string $host;
    private int $port;
    private string $uri;
    private ScopeInterface $scope;
    /** @var ArrayObject<int,ImmutableSpan> */
    private ArrayObject $storage;
    private ?ImmutableSpan $span = null;

    public function setUp(): void
    {
        $this->host = $_SERVER['MONGODB_HOST'] ?? '127.0.0.1';
        $this->port = (int) ($_SERVER['MONGODB_PORT'] ?? 27017);
        $this->uri = "mongodb://$this->host:$this->port";
        /** @psalm-suppress MixedPropertyTypeCoercion */
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

    public function test_mongodb_find_one(): void
    {
        $manager = new Manager($this->uri);

        $find = new FindOne(self::DATABASE_NAME, self::COLLECTION_NAME, ['a' => 'b']);

        $find->execute($manager->selectServer());

        $this->assertCount(1, $this->storage);
        $this->span = $this->storage->offsetGet(0);

        /** @psalm-suppress RedundantConditionGivenDocblockType */
        self::assertNotNull($this->span);
        self::assertSame('MongoDB coll.find', $this->span->getName());
        self::assertSame(SpanKind::KIND_CLIENT, $this->span->getKind());
        $attributes = $this->span->getAttributes();
        self::assertSame('mongodb', $attributes->get(TraceAttributes::DB_SYSTEM));
        self::assertSame(self::DATABASE_NAME, $attributes->get(TraceAttributes::DB_NAME));
        self::assertSame('find', $attributes->get(TraceAttributes::DB_OPERATION));
        self::assertSame(self::COLLECTION_NAME, $attributes->get(TraceAttributes::DB_MONGODB_COLLECTION));
        self::assertSame($this->host, $attributes->get(TraceAttributes::SERVER_ADDRESS));
        self::assertSame($this->port, $attributes->get(TraceAttributes::SERVER_PORT));
        self::assertSame('tcp', $attributes->get(TraceAttributes::NETWORK_TRANSPORT));
        self::assertTrue($attributes->get(MongoDBTraceAttributes::DB_MONGODB_MASTER));
        self::assertFalse($attributes->get(MongoDBTraceAttributes::DB_MONGODB_READ_ONLY));
        self::assertIsNumeric($attributes->get(MongoDBTraceAttributes::DB_MONGODB_CONNECTION_ID));
        self::assertIsNumeric($attributes->get(MongoDBTraceAttributes::DB_MONGODB_REQUEST_ID));
        self::assertIsNumeric($attributes->get(MongoDBTraceAttributes::DB_MONGODB_OPERATION_ID));
        self::assertIsNumeric($attributes->get(MongoDBTraceAttributes::DB_MONGODB_MAX_WIRE_VERSION));
        self::assertIsNumeric($attributes->get(MongoDBTraceAttributes::DB_MONGODB_MIN_WIRE_VERSION));
        self::assertIsNumeric($attributes->get(MongoDBTraceAttributes::DB_MONGODB_MAX_BSON_OBJECT_SIZE_BYTES));
        self::assertIsNumeric($attributes->get(MongoDBTraceAttributes::DB_MONGODB_MAX_MESSAGE_SIZE_BYTES));
        self::assertIsNumeric($attributes->get(MongoDBTraceAttributes::DB_MONGODB_MAX_WRITE_BATCH_SIZE));
    }
}
