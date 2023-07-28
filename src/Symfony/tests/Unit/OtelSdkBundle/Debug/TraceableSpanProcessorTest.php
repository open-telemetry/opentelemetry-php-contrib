<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Symfony\Test\Unit\OtelSdkBundle\Debug;

use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Symfony\OtelSdkBundle\DataCollector\OtelDataCollector;
use OpenTelemetry\Contrib\Symfony\OtelSdkBundle\Debug\TraceableSpanProcessor;
use OpenTelemetry\SDK\Common\Time\ClockInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use PHPUnit\Framework\TestCase;

class TraceableSpanProcessorTest extends TestCase
{
    /**
     * @dataProvider spanProcessorWithExporterDataProvider
     */
    public function testSetExporterOnConstruct(SpanProcessorInterface $spanProcessor, ?SpanExporterInterface $spanExporter)
    {
        $dataCollector = $this->createMock(OtelDataCollector::class);
        $dataCollector
            ->expects($this->once())
            ->method('setExporterData')
            ->with($spanExporter)
        ;
        $this->assertInstanceOf(TraceableSpanProcessor::class, new TraceableSpanProcessor($spanProcessor, $dataCollector));
    }

    public function testForwardsOnStart()
    {
        $readWriteSpan = $this->createMock(ReadWriteSpanInterface::class);
        $context = Context::getRoot();

        $spanProcessor = $this->createMock(SpanProcessorInterface::class);
        $spanProcessor
            ->expects($this->once())
            ->method('onStart')
            ->with($readWriteSpan, $context)
        ;

        $dataCollector = $this->createMock(OtelDataCollector::class);
        $this->assertEquals(0, count($dataCollector->collectedSpans));
        (new TraceableSpanProcessor($spanProcessor, $dataCollector))->onStart($readWriteSpan, $context);
        $this->assertEquals(1, count($dataCollector->collectedSpans));
    }

    public function testForwardsOnEnd()
    {
        $readableSpan = $this->createMock(ReadableSpanInterface::class);

        $spanProcessor = $this->createMock(SpanProcessorInterface::class);
        $spanProcessor
            ->expects($this->once())
            ->method('onEnd')
            ->with($readableSpan)
        ;

        $dataCollector = $this->createMock(OtelDataCollector::class);
        $this->assertEquals(0, count($dataCollector->collectedSpans));
        (new TraceableSpanProcessor($spanProcessor, $dataCollector))->onEnd($readableSpan);
        $this->assertEquals(1, count($dataCollector->collectedSpans));
    }

    public function testForwardsForceFlush(): void
    {
        $dataCollector = $this->createMock(OtelDataCollector::class);

        $spanProcessor = $this->createMock(SpanProcessorInterface::class);
        $spanProcessor
            ->expects($this->once())
            ->method('forceFlush')
            ->willReturn(true);
        ;
        $this->assertTrue((new TraceableSpanProcessor($spanProcessor, $dataCollector))->forceFlush());
    }

    public function testForwardsShutdown(): void
    {
        $dataCollector = $this->createMock(OtelDataCollector::class);

        $spanProcessor = $this->createMock(SpanProcessorInterface::class);
        $spanProcessor
            ->expects($this->once())
            ->method('shutdown')
            ->willReturn(false);
        ;
        $this->assertFalse((new TraceableSpanProcessor($spanProcessor, $dataCollector))->shutdown());
    }

    public function spanProcessorWithExporterDataProvider(): iterable
    {
        $spanExporter = $this->createMock(SpanExporterInterface::class);
        yield 'simple span processor with Span Exporter' => [new SimpleSpanProcessor($spanExporter), $spanExporter];
        yield 'batch span processor with Span Exporter' => [new BatchSpanProcessor($spanExporter, $this->createMock(ClockInterface::class)), $spanExporter];
    }
}
