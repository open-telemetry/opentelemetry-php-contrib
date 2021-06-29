<?php

declare(strict_types=1);

use Instrumentation\Aws\Xray\AwsXrayIdGenerator;
use OpenTelemetry\Sdk\Trace\SpanContext;
use PHPUnit\Framework\TestCase;

class AwsXrayIdGeneratorTest extends TestCase
{
    /**
     * @test
     */
    public function GeneratedTraceIdIsValid()
    {
        $idGenerator = new AwsXrayIdGenerator();
        $traceId = $idGenerator->generateTraceId();

        $this->assertEquals(1, preg_match(SpanContext::VALID_TRACE, $traceId));
    }
    
    /**
     * @test
     */
    public function GeneratedTraceIdIsUnique()
    {
        $idGenerator = new AwsXrayIdGenerator();
        $traceId1 = $idGenerator->generateTraceId();
        $traceId2 = $idGenerator->generateTraceId();

        $this->assertFalse($traceId2 === $traceId1);
    }

    /**
     * @test
     */
    public function GeneratedTraceIdIsNotNull()
    {
        $idGenerator = new AwsXrayIdGenerator();
        $traceId = $idGenerator->generateTraceId();

        $this->assertNotNull($traceId);
    }

    /**
     * @test
     */
    public function GeneratedTraceIdTimeStampIsCurrent()
    {
        $idGenerator = new AwsXrayIdGenerator();
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
        $idGenerator = new AwsXrayIdGenerator();
        $spanId = $idGenerator->generateSpanId();

        $this->assertEquals(1, preg_match(SpanContext::VALID_SPAN, $spanId));
    }
}
