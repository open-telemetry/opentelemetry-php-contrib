<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Aws\Xray;

use OpenTelemetry\Aws\Xray\IdGenerator;
use OpenTelemetry\SDK\Trace\SpanContext;
use PHPUnit\Framework\TestCase;

class IdGeneratorTest extends TestCase
{
    /**
     * @test
     */
    public function GeneratedTraceIdIsValid()
    {
        $idGenerator = new IdGenerator();
        $traceId = $idGenerator->generateTraceId();

        $this->assertEquals(1, SpanContext::isValidTraceId($traceId));
    }
    
    /**
     * @test
     */
    public function GeneratedTraceIdIsUnique()
    {
        $idGenerator = new IdGenerator();
        $traceId1 = $idGenerator->generateTraceId();
        $traceId2 = $idGenerator->generateTraceId();

        $this->assertFalse($traceId2 === $traceId1);
    }

    /**
     * @test
     */
    public function GeneratedTraceIdTimeStampIsCurrent()
    {
        $idGenerator = new IdGenerator();
        $prevTime = time();
        $traceId1 = $idGenerator->generateTraceId();
        $currTime = hexdec(substr($traceId1, 0, 8));
        $nextTime = time();

        $this->assertGreaterThanOrEqual($prevTime, $currTime);
        $this->assertLessThanOrEqual($nextTime, $currTime);
    }

    /**
     * @test
     */
    public function generatedSpanIdIsValid()
    {
        $idGenerator = new IdGenerator();
        $spanId = $idGenerator->generateSpanId();

        $this->assertEquals(1, SpanContext::isValidSpanId($spanId));
    }
}
