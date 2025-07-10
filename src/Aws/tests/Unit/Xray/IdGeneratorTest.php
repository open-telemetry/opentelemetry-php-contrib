<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Aws\Unit\Xray;

use OpenTelemetry\API\Trace\SpanContextValidator;
use OpenTelemetry\Contrib\Aws\Xray\IdGenerator;
use PHPUnit\Framework\TestCase;

class IdGeneratorTest extends TestCase
{
    public function testGeneratedTraceIdIsValid()
    {
        $this->assertTrue(
            SpanContextValidator::isValidTraceId(
                (new IdGenerator())->generateTraceId()
            )
        );
    }

    public function testGeneratedTraceIdIsUnique()
    {
        $idGenerator = new IdGenerator();

        $this->assertNotEquals(
            $idGenerator->generateTraceId(),
            $idGenerator->generateTraceId()
        );
    }

    public function testGeneratedTraceIdTimeStampIsCurrent()
    {
        $idGenerator = new IdGenerator();
        $prevTime = time();
        $traceId1 = $idGenerator->generateTraceId();
        $currTime = hexdec(substr($traceId1, 0, 8));
        $nextTime = time();

        $this->assertGreaterThanOrEqual($prevTime, $currTime);
        $this->assertLessThanOrEqual($nextTime, $currTime);
    }

    public function testGeneratedSpanIdIsValid()
    {
        $this->assertTrue(
            SpanContextValidator::isValidSpanId(
                (new IdGenerator())->generateSpanId()
            )
        );
    }
}
