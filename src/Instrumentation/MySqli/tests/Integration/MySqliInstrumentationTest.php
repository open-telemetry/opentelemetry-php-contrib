<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\MySqli\Integration;

use ArrayObject;
use mysqli;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\TraceAttributes;
use PHPUnit\Framework\TestCase;

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

    public function test_mysqli_connect(): void
    {
        $mysqli = new mysqli($this->mysqlHost, $this->user, $this->passwd, $this->database);
        $mysqli->connect($this->mysqlHost, $this->user, $this->passwd, $this->database);
        mysqli_connect($this->mysqlHost, $this->user, $this->passwd, $this->database);

        $mysqli->real_connect($this->mysqlHost, $this->user, $this->passwd, $this->database);
        mysqli_real_connect($mysqli, $this->mysqlHost, $this->user, $this->passwd, $this->database);

        $this->assertCount(5, $this->storage);

        $span = $this->storage->offsetGet(0);
        $this->assertSame('mysqli::__construct', $span->getName());

        $span = $this->storage->offsetGet(1);
        $this->assertSame('mysqli::connect', $span->getName());

        $span = $this->storage->offsetGet(2);
        $this->assertSame('mysqli_connect', $span->getName());

        $span = $this->storage->offsetGet(3);
        $this->assertSame('mysqli::real_connect', $span->getName());

        $span = $this->storage->offsetGet(4);
        $this->assertSame('mysqli_real_connect', $span->getName());

        for ($i = 0; $i < 5; $i++) {
            $span = $this->storage->offsetGet($i);
            $this->assertEquals($this->mysqlHost, $span->getAttributes()->get(TraceAttributes::SERVER_ADDRESS));
            $this->assertEquals($this->user, $span->getAttributes()->get(TraceAttributes::DB_USER));
            $this->assertEquals($this->database, $span->getAttributes()->get(TraceAttributes::DB_NAMESPACE));
            $this->assertEquals('mysql', $span->getAttributes()->get(TraceAttributes::DB_SYSTEM));
        }
    }

    // to be continued
}
