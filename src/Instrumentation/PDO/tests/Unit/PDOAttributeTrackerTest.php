<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\PDO\tests\Unit;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Contrib\Instrumentation\PDO\PDOTracker;
use OpenTelemetry\SemConv\TraceAttributes;
use PHPUnit\Framework\TestCase;

class PDOAttributeTrackerTest extends TestCase
{
    public function testPdoCanBeTracked(): void
    {
        $dsn = 'sqlite::memory:';
        $pdo = new \PDO($dsn);

        $objectMap = new PDOTracker();
        $objectMap->trackPdoAttributes($pdo, $dsn);
        $attributes = $objectMap->trackedAttributesForPdo($pdo);
        $span = Span::getInvalid();

        /** @psalm-suppress InvalidArgument */
        $this->assertContains(TraceAttributes::DB_SYSTEM, array_keys($attributes));
        /** @psalm-suppress InvalidArgument */
        $this->assertContains(TraceAttributes::DB_NAME, array_keys($attributes));
        /** @psalm-suppress InvalidArrayAccess */
        $this->assertSame('memory', $attributes[TraceAttributes::DB_NAME]);

        $stmt = $pdo->prepare('SELECT NULL LIMIT 0;');
        $objectMap->trackStatement($stmt, $pdo, $span->getContext());
        $attributes = $objectMap->trackedAttributesForStatement($stmt);

        /** @psalm-suppress InvalidArgument */
        $this->assertContains(TraceAttributes::DB_SYSTEM, array_keys($attributes));
        /** @psalm-suppress InvalidArrayAccess */
        $this->assertEquals('sqlite', $attributes[TraceAttributes::DB_SYSTEM]);
        $this->assertSame($span->getContext(), $objectMap->getSpanForPreparedStatement($stmt));
    }
}
