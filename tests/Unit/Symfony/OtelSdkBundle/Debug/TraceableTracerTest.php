<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\Debug;

use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Symfony\OtelSdkBundle\DataCollector\OtelDataCollector;
use OpenTelemetry\Symfony\OtelSdkBundle\Debug\TraceableTracer;
use PHPUnit\Framework\TestCase;

class TraceableTracerTest extends TestCase
{
    public function testForwardsSpanBuilder()
    {
        $tracer = $this->createMock(TracerInterface::class);
        $tracer
            ->expects($this->once())
            ->method('spanBuilder')
            ->with('spanName')
            ->willReturn($this->createMock(SpanBuilderInterface::class));

        $dataCollector = $this->createMock(OtelDataCollector::class);
        $dataCollector
            ->expects($this->once())
            ->method('setTracer')
            ->with($tracer)
        ;
        $this->assertInstanceOf(SpanBuilderInterface::class, (new TraceableTracer($tracer, $dataCollector))->spanBuilder('spanName'));
    }
}
