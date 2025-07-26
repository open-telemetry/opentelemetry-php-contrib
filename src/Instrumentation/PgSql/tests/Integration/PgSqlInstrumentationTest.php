<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\PgSql\Integration;

use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\TraceAttributes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PgSqlInstrumentationTest extends TestCase
{
    private ArrayObject $storage;
    private ScopeInterface $scope;

    private string $pgSqlHost;
    private int $pgSqlPort;

    private string $user;
    private ?string $passwd;
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
            ->activate();

        $this->pgSqlHost = getenv('PGSQL_HOST') ?: '127.0.0.1';
        $this->pgSqlPort = (int) (getenv('PGSQL_PORT') ?: '5432');

        $this->user = getenv('PGSQL_USER') ?: 'postgres';
        $this->passwd = getenv('PGSQL_PASSWD') ?: null;
        $this->database = getenv('PGSQL_DATABASE') ?: 'postgres';
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    public static function pgConnectProvider(): array
    {
        return [
            [null, true],
            ['0.0.0.0', false],
        ];
    }

    #[DataProvider('pgConnectProvider')]
    public function testPgConnect(?string $hostOverride, bool $expectSuccess): void
    {
        $this->assertCount(0, $this->storage);

        if ($expectSuccess) {
            $this->assertNotFalse(pg_connect($this->getConnectionString($hostOverride)));
        } else {
            $this->assertFalse(@pg_connect($this->getConnectionString($hostOverride)));
        }

        $this->assertCount(1, $this->storage);
        $span = $this->storage->offsetGet(0);

        $this->assertSame('pg_connect', $span->getName());
        $this->assertSame(SpanKind::KIND_CLIENT, $span->getKind());

        if ($expectSuccess) {
            $this->assertConnectionAttributes($span->getAttributes());
        } else {
            $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        }
    }

    public static function pgQueryProvider(): array
    {
        return [
            ['SELECT 1', true, false],
            ['SELECT 1', true, true],
            ["SELECT 'abc", false, false],
        ];
    }

    #[DataProvider('pgQueryProvider')]
    public function testPgQuery(string $query, bool $expectSuccess, bool $useDefaultConnection): void
    {
        $this->assertCount(0, $this->storage);

        $connection = pg_connect($this->getConnectionString());
        $this->assertNotFalse($connection);

        if ($useDefaultConnection) {
            if ($expectSuccess) {
                $this->assertNotFalse(@pg_query($query));
            } else {
                $this->assertFalse(@pg_query($query));
            }
        } else {
            if ($expectSuccess) {
                $this->assertNotFalse(pg_query($connection, $query));
            } else {
                $this->assertFalse(@pg_query($connection, $query));
            }
        }

        pg_close($connection);

        $this->assertCount(2, $this->storage);
        $span = $this->storage->offsetGet(1);

        $this->assertSame('pg_query', $span->getName());
        $this->assertSame(SpanKind::KIND_CLIENT, $span->getKind());

        if (!$useDefaultConnection) {
            $this->assertConnectionAttributes($span->getAttributes());
        }

        // Per https://opentelemetry.io/docs/specs/semconv/database/database-spans/#sanitization-of-dbquerytext:
        // "The db.query.text SHOULD be collected by default only if there is sanitization that excludes sensitive information."
        $this->assertFalse($span->getAttributes()->has(TraceAttributes::DB_QUERY_TEXT));

        if ($expectSuccess) {
            $this->assertSame(1, $span->getAttributes()->get(TraceAttributes::DB_RESPONSE_RETURNED_ROWS));
        } else {
            $this->assertFalse($span->getAttributes()->has(TraceAttributes::DB_RESPONSE_RETURNED_ROWS));
            $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
            $this->assertNotEmpty($span->getStatus()->getDescription());
        }
    }

    public static function pgQueryParamsProvider(): array
    {
        return [
            ['SELECT $1, $2', [1, 2], true, false],
            ['SELECT $1, $2', [1, 2], true, true],
            ['SELECT $1, $2, $3', [1, 2], false, false],
        ];
    }

    #[DataProvider('pgQueryParamsProvider')]
    public function testPgQueryParams(string $query, array $params, bool $expectSuccess, bool $useDefaultConnection): void
    {
        $this->assertCount(0, $this->storage);

        $connection = pg_connect($this->getConnectionString());
        $this->assertNotFalse($connection);

        if ($useDefaultConnection) {
            if ($expectSuccess) {
                $this->assertNotFalse(@pg_query_params($query, $params));
            } else {
                $this->assertFalse(@pg_query_params($query, $params));
            }
        } else {
            if ($expectSuccess) {
                $this->assertNotFalse(pg_query_params($connection, $query, $params));
            } else {
                $this->assertFalse(@pg_query_params($connection, $query, $params));
            }
        }

        pg_close($connection);

        $this->assertCount(2, $this->storage);
        $span = $this->storage->offsetGet(1);

        $this->assertSame('pg_query_params', $span->getName());
        $this->assertSame(SpanKind::KIND_CLIENT, $span->getKind());

        if (!$useDefaultConnection) {
            $this->assertConnectionAttributes($span->getAttributes());
        }

        $this->assertSame($query, $span->getAttributes()->get(TraceAttributes::DB_QUERY_TEXT));

        foreach ($params as $i => $v) {
            $attribute = TraceAttributes::DB_QUERY_PARAMETER . ".$i";
            $this->assertSame((string) $v, $span->getAttributes()->get($attribute));
        }

        if ($expectSuccess) {
            $this->assertSame(1, $span->getAttributes()->get(TraceAttributes::DB_RESPONSE_RETURNED_ROWS));
        } else {
            $this->assertFalse($span->getAttributes()->has(TraceAttributes::DB_RESPONSE_RETURNED_ROWS));
            $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
            $this->assertNotEmpty($span->getStatus()->getDescription());
        }
    }

    private function getConnectionString(?string $hostOverride = null): string
    {
        $host = str_replace("'", "\\'", $hostOverride ?: $this->pgSqlHost);
        $port = (string) $this->pgSqlPort;
        $dbname = str_replace("'", "\\'", $this->database);
        $user = str_replace("'", "\\'", $this->user);

        $str = "host='$host' port='$port' dbname='$dbname' user='$user'";

        if ($this->passwd) {
            $password = str_replace("'", "\\'", $this->passwd);
            $str .= " password='$password'";
        }

        return $str;
    }

    private function assertConnectionAttributes(AttributesInterface $attributes): void
    {
        $this->assertSame($this->database, $attributes->get(TraceAttributes::DB_NAMESPACE));
        $this->assertSame('postgresql', $attributes->get(TraceAttributes::DB_SYSTEM_NAME));
        $this->assertSame($this->pgSqlHost, $attributes->get(TraceAttributes::SERVER_ADDRESS));
        $this->assertSame($this->pgSqlPort, $attributes->get(TraceAttributes::SERVER_PORT));
    }
}
