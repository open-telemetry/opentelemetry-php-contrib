<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Symfony\Test\Unit\OtelSdkBundle\DataCollector;

use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\Contrib\Symfony\OtelSdkBundle\DataCollector\OtelDataCollector;
use OpenTelemetry\Contrib\Zipkin\Exporter as ZipkinExporter;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\RandomIdGenerator;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanBuilder;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanLimitsBuilder;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerSharedState;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\VarDumper\Cloner\Data;

class OtelDataCollectorTest extends TestCase
{
    use ProphecyTrait;

    public function testReset(): void
    {
        $dataCollector = new OtelDataCollector();
        $dataCollector->setExporterData($this->createMock(SpanExporterInterface::class));
        $this->assertEquals(1, count($dataCollector->getData()));
        $dataCollector->reset();
        $this->assertEquals(0, count($dataCollector->getData()));
    }

    public function testGetName(): void
    {
        $dataCollector = new OtelDataCollector();
        $this->assertEquals('otel', $dataCollector->getName());
    }

    public function testGetClassLocations(): void
    {
        $dataCollector = new OtelDataCollector();
        $this->assertEquals([
            'class' => 'OtelDataCollectorTest',
            'file' => __FILE__,
        ], $dataCollector->getClassLocation(get_class($this)));
    }

    /**
     * @dataProvider exporterDataProvider
     */
    public function testSetExporterData(SpanExporterInterface $spanExporter, ?array $expectedExporterData): void
    {
        $dataCollector = new OtelDataCollector();
        $dataCollector->setExporterData($spanExporter);
        $this->assertEquals($expectedExporterData, $dataCollector->getData()['exporter']);
    }

    public function testLateCollectWithoutSharedState(): void
    {
        $dataCollector = new OtelDataCollector();
        $dataCollector->lateCollect();
        $this->assertEquals(['spans' => []], $dataCollector->getData());
    }

    public function testLateCollectWithTracerProviderSharedState(): void
    {
        $tracerProvider = new TracerProvider(
            [new SimpleSpanProcessor($this->createMock(SpanExporterInterface::class))],
            new AlwaysOnSampler(),
            ResourceInfoFactory::emptyResource(),
            (new SpanLimitsBuilder())->build(),
            new RandomIdGenerator(),
        );
        $dataCollector = new OtelDataCollector();
        $dataCollector->setTracerProvider($tracerProvider);
        $dataCollector->lateCollect();
        $this->assertEquals([], $dataCollector->getData()['spans']);
        $this->assertEquals([
            'class' => 'RandomIdGenerator',
            'file' => (new \ReflectionClass(RandomIdGenerator::class))->getFileName(),
        ], $dataCollector->getData()['id_generator']);
        $this->assertEquals([
            'class' => 'AlwaysOnSampler',
            'file' => (new \ReflectionClass(AlwaysOnSampler::class))->getFileName(),
        ], $dataCollector->getData()['sampler']);
        $this->assertEquals([
            'class' => 'SimpleSpanProcessor',
            'file' => (new \ReflectionClass(SimpleSpanProcessor::class))->getFileName(),
        ], $dataCollector->getData()['span_processor']);
        $this->assertInstanceOf(Data::class, $dataCollector->getData()['resource_info_attributes']);
        $this->assertInstanceOf(Data::class, $dataCollector->getData()['span_limits']);
    }

    public function testOrderedSpans(): void
    {
        $sharedState = new TracerSharedState(
            new RandomIdGenerator(),
            ResourceInfoFactory::emptyResource(),
            (new SpanLimitsBuilder())->build(),
            new AlwaysOnSampler(),
            [new SimpleSpanProcessor($this->createMock(SpanExporterInterface::class))],
        );
        $span = (new SpanBuilder('test', $this->createMock(InstrumentationScopeInterface::class), $sharedState))->startSpan();
        $dataCollector = new OtelDataCollector();
        $dataCollector->collectedSpans['span_id'] = $span;
        $dataCollector->lateCollect();
        $this->assertArrayHasKey('root', $dataCollector->getData()['spans']);
        $this->assertEquals(0, count($dataCollector->getData()['spans']['root']['children']));
        $this->assertEquals('test', $dataCollector->getData()['spans']['root']['data']['name']);
    }

    /**
     * @psalm-suppress ArgumentTypeCoercion
     */
    public function exporterDataProvider(): iterable
    {
        $transportProphecy = $this->prophesize(TransportInterface::class);
        $transportProphecy->contentType()->willReturn('application/json');
        $transport = $transportProphecy->reveal();

        yield 'Zipkin exporter' => [
            new ZipkinExporter($transport),
            [
                'class' => 'exporter-zipkin/Exporter',
                'file' => (new \ReflectionClass(ZipkinExporter::class))->getFileName(),
            ],
        ];
        yield 'OtlpHttp exporter' => [
            new SpanExporter($transport),
            [
                'class' => 'exporter-otlp/SpanExporter',
                'file' => (new \ReflectionClass(SpanExporter::class))->getFileName(),
            ],
        ];
    }
}
