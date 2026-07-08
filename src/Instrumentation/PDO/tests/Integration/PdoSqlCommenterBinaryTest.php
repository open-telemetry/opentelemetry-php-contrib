<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\PDO\Integration;

use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Contrib\SqlCommenter\SqlCommenter;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for #1960.
 *
 * When sqlcommenter is installed and the driver is mysql/postgresql, the PDO::query and
 * PDO::exec pre-hooks substitute their query back as the executed statement. They must
 * inject into the raw query so binary literals survive on the wire, rather than
 * UTF-8-converting the executed statement and replacing non-UTF-8 bytes with `?`.
 */
class PdoSqlCommenterBinaryTest extends TestCase
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

    public function test_binary_literal_survives_sqlcommenter_injection_via_exec(): void
    {
        $pdo = $this->connect();

        // sha1("hello world") — 20 bytes containing several high (non-UTF-8) bytes.
        $bin = hex2bin('2aae6c35c94fcfb415dbe95f408b9ce91ee846ed');
        $this->assertIsString($bin);

        $pdo->exec('INSERT INTO otel_binary_1960 (h) VALUES (' . $pdo->quote($bin) . ')');
        $stored = $this->fetchStored($pdo);

        $pdo->exec('DROP TABLE IF EXISTS otel_binary_1960');

        $this->assertSame(bin2hex($bin), bin2hex($stored), 'PDO::exec must not corrupt binary literals when sqlcommenter is installed');
    }

    public function test_binary_literal_survives_sqlcommenter_injection_via_query(): void
    {
        $pdo = $this->connect();

        // sha1("") — 20 bytes.
        $bin = hex2bin('da39a3ee5e6b4b0d3255bfef95601890afd80709');
        $this->assertIsString($bin);

        $pdo->query('INSERT INTO otel_binary_1960 (h) VALUES (' . $pdo->quote($bin) . ')');
        $stored = $this->fetchStored($pdo);

        $pdo->exec('DROP TABLE IF EXISTS otel_binary_1960');

        $this->assertSame(bin2hex($bin), bin2hex($stored), 'PDO::query must not corrupt binary literals when sqlcommenter is installed');
    }

    private function connect(): PDO
    {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_mysql driver not available');
        }
        // The corrupting code path is the sqlcommenter branch of the pre-hook, so it must
        // actually be reachable for this test to be meaningful.
        $this->assertTrue(class_exists(SqlCommenter::class), 'sqlcommenter must be installed to exercise this path');

        $host = getenv('MYSQL_HOST') ?: '127.0.0.1';
        $pdo = new PDO("mysql:host={$host};dbname=otel_db;charset=utf8mb4", 'otel_user', 'otel_passwd', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('DROP TABLE IF EXISTS otel_binary_1960');
        $pdo->exec('CREATE TABLE otel_binary_1960 (h BINARY(20) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        return $pdo;
    }

    private function fetchStored(PDO $pdo): string
    {
        $result = $pdo->query('SELECT h FROM otel_binary_1960');
        $this->assertNotFalse($result);
        $stored = $result->fetchColumn();
        $this->assertIsString($stored);

        return $stored;
    }
}
