<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Symfony\Test\Unit\OtelSdkBundle\Debug;

use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Contrib\Symfony\OtelSdkBundle\DataCollector\OtelDataCollector;
use OpenTelemetry\Contrib\Symfony\OtelSdkBundle\Debug\TraceableTracerProvider;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\RandomIdGenerator;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanLimitsBuilder;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
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

    public function testForwardsGetTracer(): void
    {
        $tracerProvider = new TracerProvider(
            [new SimpleSpanProcessor($this->createMock(SpanExporterInterface::class))],
            new AlwaysOnSampler(),
            ResourceInfoFactory::emptyResource(),
            (new SpanLimitsBuilder())->build(),
            new RandomIdGenerator(),
        );
        $dataCollector = $this->createMock(OtelDataCollector::class);

        $this->assertInstanceOf(TracerInterface::class, (new TraceableTracerProvider($tracerProvider, $dataCollector))->getTracer('test'));
    }
}
