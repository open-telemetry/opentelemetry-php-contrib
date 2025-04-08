<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\PDO\tests\Unit;

use OpenTelemetry\Contrib\Instrumentation\PDO\PDOInstrumentation;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PDOInstrumentationTest extends TestCase
{
    /**
     * @dataProvider dsnProvider
     */
    public function testExtractFromDSN(string $dsn, string $expectedHost, ?int $expectedPort): void
    {
        // Use reflection to access the private method
        $reflectionMethod = new ReflectionMethod(PDOInstrumentation::class, 'extractFromDSN');
        $reflectionMethod->setAccessible(true);

        $result = $reflectionMethod->invoke(null, $dsn);

        $this->assertSame($expectedHost, $result[0]);
        $this->assertSame($expectedPort, $result[1]);
    }

    /**
     * Data provider for testExtractFromDSN
     *
     * @return array<string, array{string, string, int|null}>
     */
    public function dsnProvider(): array
    {
        return [
            'standard format with host and port' => [
                'mysql:host=localhost;port=3306;dbname=test',
                'localhost',
                3306,
            ],
            'format with host but no port' => [
                'mysql:host=localhost;dbname=test',
                'localhost',
                null,
            ],
            'format with port but using alternative format' => [
                'mysql:localhost:3306;dbname=test',
                'localhost',
                3306,
            ],
            'format with neither host parameter nor port parameter' => [
                'mysql:localhost;dbname=test',
                'localhost',
                null,
            ],
            'format with IP address as host' => [
                'mysql:host=127.0.0.1;port=3306;dbname=test',
                '127.0.0.1',
                3306,
            ],
            'format with domain name as host' => [
                'mysql:host=example.com;port=3306;dbname=test',
                'example.com',
                3306,
            ],
            'PostgreSQL format' => [
                'pgsql:host=localhost;port=5432;dbname=test',
                'localhost',
                5432,
            ],
            'SQLite format' => [
                'sqlite:/path/to/database.sqlite',
                'unknown',
                null,
            ],
            'SQLite in-memory format' => [
                'sqlite::memory:',
                'unknown',
                null,
            ],
            'Oracle format' => [
                'oci:host=localhost;port=1521;dbname=test',
                'localhost',
                1521,
            ],
            'SQL Server format' => [
                'sqlsrv:Server=localhost,1433;Database=test',
                'unknown',
                null,
            ],
            'MySQL format with host in DSN prefix' => [
                'mysql:dbname=test;charset=utf8',
                'dbname=test',
                null,
            ],
            'MySQL format with host in DSN prefix and port' => [
                'mysql:dbname=test;port=3307;charset=utf8',
                'dbname=test',
                3307,
            ],
            'MySQL format with host in DSN prefix and colon port' => [
                'mysql:127.0.0.1:3308;dbname=test',
                '127.0.0.1',
                127,
            ],
        ];
    }
}
