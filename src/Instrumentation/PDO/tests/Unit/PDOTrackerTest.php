<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\PDO\tests\Unit;

use OpenTelemetry\Contrib\Instrumentation\PDO\PDOTracker;
use OpenTelemetry\SemConv\TraceAttributes;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PDOTrackerTest extends TestCase
{
    /**
     * @dataProvider dsnProvider
     */
    public function testExtractAttributesFromDSN(string $dsn, array $expectedAttributes): void
    {
        // Use reflection to access the private method
        $reflectionMethod = new ReflectionMethod(PDOTracker::class, 'extractAttributesFromDSN');
        $reflectionMethod->setAccessible(true);

        $result = $reflectionMethod->invoke(null, $dsn);

        foreach ($expectedAttributes as $key => $value) {
            $this->assertArrayHasKey($key, $result);
            $this->assertSame($value, $result[$key]);
        }
    }

    /**
     * Data provider for testExtractAttributesFromDSN
     *
     * @return array<string, array{string, array<string, mixed>}>
     */
    public function dsnProvider(): array
    {
        return [
            'standard format with host and port' => [
                'mysql:host=localhost;port=3306;dbname=test',
                [
                    TraceAttributes::SERVER_ADDRESS => 'localhost',
                    TraceAttributes::SERVER_PORT => 3306,
                    TraceAttributes::DB_NAMESPACE => 'test',
                ],
            ],
            'format with host but no port' => [
                'mysql:host=localhost;dbname=test',
                [
                    TraceAttributes::SERVER_ADDRESS => 'localhost',
                    TraceAttributes::DB_NAMESPACE => 'test',
                ],
            ],
            'format with port but using alternative format' => [
                'mysql:localhost:3306;dbname=test',
                [
                    TraceAttributes::SERVER_ADDRESS => 'localhost',
                    TraceAttributes::SERVER_PORT => 3306,
                    TraceAttributes::DB_NAMESPACE => 'test',
                ],
            ],
            'format with neither host parameter nor port parameter' => [
                'mysql:localhost;dbname=test',
                [
                    TraceAttributes::SERVER_ADDRESS => 'localhost',
                    TraceAttributes::DB_NAMESPACE => 'test',
                ],
            ],
            'format with IP address as host' => [
                'mysql:host=127.0.0.1;port=3306;dbname=test',
                [
                    TraceAttributes::SERVER_ADDRESS => '127.0.0.1',
                    TraceAttributes::SERVER_PORT => 3306,
                    TraceAttributes::DB_NAMESPACE => 'test',
                ],
            ],
            'format with domain name as host' => [
                'mysql:host=example.com;port=3306;dbname=test',
                [
                    TraceAttributes::SERVER_ADDRESS => 'example.com',
                    TraceAttributes::SERVER_PORT => 3306,
                    TraceAttributes::DB_NAMESPACE => 'test',
                ],
            ],
            'PostgreSQL format' => [
                'pgsql:host=localhost;port=5432;dbname=test',
                [
                    TraceAttributes::SERVER_ADDRESS => 'localhost',
                    TraceAttributes::SERVER_PORT => 5432,
                    TraceAttributes::DB_NAMESPACE => 'test',
                ],
            ],
            'SQLite format' => [
                'sqlite:/path/to/database.sqlite',
                [
                    TraceAttributes::DB_SYSTEM_NAME => 'sqlite',
                    TraceAttributes::DB_NAMESPACE => '/path/to/database.sqlite',
                ],
            ],
            'SQLite in-memory format' => [
                'sqlite::memory:',
                [
                    TraceAttributes::DB_SYSTEM_NAME => 'sqlite',
                    TraceAttributes::DB_NAMESPACE => 'memory',
                ],
            ],
            'Oracle format' => [
                'oci:host=localhost;port=1521;dbname=test',
                [
                    TraceAttributes::SERVER_ADDRESS => 'localhost',
                    TraceAttributes::SERVER_PORT => 1521,
                    TraceAttributes::DB_NAMESPACE => 'test',
                ],
            ],
            'SQL Server format' => [
                'sqlsrv:Server=localhost,1433;Database=test',
                [
                    TraceAttributes::SERVER_ADDRESS => 'localhost',
                    TraceAttributes::SERVER_PORT => 1433,
                    TraceAttributes::DB_NAMESPACE => 'test',
                ],
            ],
            'MySQL format with host in DSN prefix' => [
                'mysql:dbname=test;charset=utf8',
                [
                    TraceAttributes::DB_NAMESPACE => 'test',
                ],
            ],
            'MySQL format with host in DSN prefix and port' => [
                'mysql:dbname=test;port=3307;charset=utf8',
                [
                    TraceAttributes::SERVER_PORT => 3307,
                    TraceAttributes::DB_NAMESPACE => 'test',
                ],
            ],
            'MySQL format with host in DSN prefix and colon port' => [
                'mysql:127.0.0.1:3308;dbname=test',
                [
                    TraceAttributes::SERVER_ADDRESS => '127.0.0.1',
                    TraceAttributes::SERVER_PORT => 3308,
                    TraceAttributes::DB_NAMESPACE => 'test',
                ],
            ],
        ];
    }
}
