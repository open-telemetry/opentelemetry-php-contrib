<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\Debug;

use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Trace\Tracer;
use OpenTelemetry\Symfony\OtelSdkBundle\DataCollector\OtelDataCollector;
use OpenTelemetry\Symfony\OtelSdkBundle\Debug\TraceableTracer;
use PHPUnit\Framework\TestCase;

class TraceableTracerTest extends TestCase
{
    /**
     * @dataProvider spanNameDataProvider
     */
    public function testForwardsSpanBuilder(string $spanName, string $expectedSpanName): void
    {
        $tracer = $this->createMock(TracerInterface::class);
        $tracer
            ->expects($this->once())
            ->method('spanBuilder')
            ->with($expectedSpanName)
            ->willReturn($this->createMock(SpanBuilderInterface::class));

        $dataCollector = $this->createMock(OtelDataCollector::class);
        $dataCollector
            ->expects($this->once())
            ->method('setTracer')
            ->with($tracer)
        ;
        /**
         * @psalm-suppress ArgumentTypeCoercion
         * @phpstan-ignore-next-line
         */
        $this->assertInstanceOf(SpanBuilderInterface::class, (new TraceableTracer($tracer, $dataCollector))->spanBuilder($spanName));
    }

    public function spanNameDataProvider(): iterable
    {
        yield 'whitespace span name' => [' ', Tracer::FALLBACK_SPAN_NAME];
        yield 'non-empty span name' => ['spanName', 'spanName'];
    }
}
