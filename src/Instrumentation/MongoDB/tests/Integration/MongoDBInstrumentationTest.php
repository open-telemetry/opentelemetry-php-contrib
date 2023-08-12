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
    private ScopeInterface $scope;
    /** @var ArrayObject<int,ImmutableSpan> */
    private ArrayObject $storage;
    private ?ImmutableSpan $span = null;

    public function setUp(): void
    {
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
        $manager = new Manager('mongodb://127.0.0.1:27017');

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
        self::assertSame('mongodb://127.0.0.1:27017/db', $attributes->get(TraceAttributes::DB_CONNECTION_STRING));
        self::assertSame('find', $attributes->get(TraceAttributes::DB_OPERATION));
        self::assertSame(self::COLLECTION_NAME, $attributes->get(TraceAttributes::DB_MONGODB_COLLECTION));
        self::assertSame('127.0.0.1', $attributes->get(TraceAttributes::NET_PEER_NAME));
        self::assertSame(27017, $attributes->get(TraceAttributes::NET_PEER_PORT));
        self::assertSame('tcp', $attributes->get(TraceAttributes::NET_TRANSPORT));
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
