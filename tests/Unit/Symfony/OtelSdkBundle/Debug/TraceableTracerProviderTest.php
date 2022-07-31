<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\Debug;

use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use OpenTelemetry\Symfony\OtelSdkBundle\DataCollector\OtelDataCollector;
use OpenTelemetry\Symfony\OtelSdkBundle\Debug\TraceableTracerProvider;
use PHPUnit\Framework\TestCase;

class TraceableTracerProviderTest extends TestCase
{
    public function testForwardsForceFlush()
    {
        $tracerProvider = $this->createMock(TracerProviderInterface::class);
        $tracerProvider
            ->expects($this->once())
            ->method('forceFlush')
            ->willReturn(true);

        $dataCollector = $this->createMock(OtelDataCollector::class);
        $dataCollector
            ->expects($this->once())
            ->method('setTracerProvider')
            ->with($tracerProvider)
        ;
        $this->assertTrue((new TraceableTracerProvider($tracerProvider, $dataCollector))->forceFlush());
    }

    public function testForwardsShutdown()
    {
        $tracerProvider = $this->createMock(TracerProviderInterface::class);
        $tracerProvider
            ->expects($this->once())
            ->method('shutdown')
            ->willReturn(true);

        $dataCollector = $this->createMock(OtelDataCollector::class);
        $dataCollector
            ->expects($this->once())
            ->method('setTracerProvider')
            ->with($tracerProvider)
        ;
        $this->assertTrue((new TraceableTracerProvider($tracerProvider, $dataCollector))->shutdown());
    }
}
