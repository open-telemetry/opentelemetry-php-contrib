<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\MySqli\Integration;

use ArrayObject;
use mysqli;
use mysqli_result;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Contrib\SqlCommenter\SqlCommenter;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for #1960.
 *
 * When sqlcommenter is installed, the mysqli query pre-hook substitutes its (possibly
 * modified) query back as the executed statement. It must inject into the raw query so
 * binary literals survive on the wire, rather than UTF-8-converting the executed
 * statement and replacing non-UTF-8 bytes with `?`.
 */
class MySqliSqlCommenterBinaryTest extends TestCase
{
    private ScopeInterface $scope;
    /** @var ArrayObject<int, ImmutableSpan> */
    private ArrayObject $storage;

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
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    public function test_binary_literal_survives_sqlcommenter_injection(): void
    {
        // The corrupting code path is the sqlcommenter branch of the pre-hook, so it must
        // actually be reachable for this test to be meaningful.
        $this->assertTrue(class_exists(SqlCommenter::class), 'sqlcommenter must be installed to exercise this path');

        $host = getenv('MYSQL_HOST') ?: '127.0.0.1';
        $mysqli = new mysqli($host, 'otel_user', 'otel_passwd', 'otel_db');
        $mysqli->set_charset('utf8mb4');

        $mysqli->query('DROP TABLE IF EXISTS otel_binary_1960');
        $mysqli->query('CREATE TABLE otel_binary_1960 (h BINARY(20) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        // sha1("hello world") — 20 bytes containing several high (non-UTF-8) bytes.
        $bin = hex2bin('2aae6c35c94fcfb415dbe95f408b9ce91ee846ed');
        $this->assertIsString($bin);

        $escaped = "'" . $mysqli->real_escape_string($bin) . "'";
        $mysqli->query("INSERT INTO otel_binary_1960 (h) VALUES ($escaped)");

        $result = $mysqli->query('SELECT h FROM otel_binary_1960');
        $this->assertInstanceOf(mysqli_result::class, $result);
        $row = $result->fetch_row();
        $this->assertIsArray($row);
        $this->assertIsString($row[0]);

        $mysqli->query('DROP TABLE IF EXISTS otel_binary_1960');
        $mysqli->close();

        $this->assertSame(
            bin2hex($bin),
            bin2hex($row[0]),
            'binary column bytes must be stored unmodified when sqlcommenter is installed',
        );
    }
}
