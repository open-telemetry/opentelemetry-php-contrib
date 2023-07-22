<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\PDO\tests\Unit;

use OpenTelemetry\Contrib\Instrumentation\PDO\PDOAttributeTracker;
use OpenTelemetry\SemConv\TraceAttributes;
use PHPUnit\Framework\TestCase;

class PDOAttributeTrackerTest extends TestCase
{
    public function testPdoCanBeTracked()
    {
        $pdo = new \PDO('sqlite::memory:');

        $objectMap = new PDOAttributeTracker();
        $objectMap->trackPdoAttributes($pdo);
        $attributes = $objectMap->trackedAttributesForPdo($pdo);

        $this->assertContains(TraceAttributes::DB_SYSTEM, array_keys($attributes));

        $stmt = $pdo->prepare('SELECT NULL LIMIT 0;');
        $objectMap->trackStatementToPdoMapping($stmt, $pdo);
        $attributes = $objectMap->trackedAttributesForStatement($stmt);

        /** @psalm-suppress InvalidArgument */
        $this->assertContains(TraceAttributes::DB_SYSTEM, array_keys($attributes));
        /** @psalm-suppress InvalidArrayAccess */
        $this->assertEquals('sqlite', $attributes[TraceAttributes::DB_SYSTEM]);
    }
}
