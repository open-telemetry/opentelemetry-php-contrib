<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Runtime\Tests\Unit;

use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObservableCounterInterface;
use OpenTelemetry\API\Metrics\ObservableGaugeInterface;
use OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface;
use OpenTelemetry\Contrib\Metrics\Runtime\RuntimeMetrics;
use OpenTelemetry\Contrib\Metrics\Runtime\RuntimeMetricsConfig;
use PHPUnit\Framework\TestCase;

class RuntimeMetricsTest extends TestCase
{
    private function makeMeter(): MeterInterface
    {
        $meter = $this->createMock(MeterInterface::class);
        $meter->method('createObservableUpDownCounter')
            ->willReturn($this->createMock(ObservableUpDownCounterInterface::class));
        $meter->method('createObservableGauge')
            ->willReturn($this->createMock(ObservableGaugeInterface::class));
        $meter->method('createObservableCounter')
            ->willReturn($this->createMock(ObservableCounterInterface::class));
        $meter->method('batchObserve')
            ->willReturn($this->createMock(ObservableCallbackInterface::class));

        return $meter;
    }

    private function makeMeterProvider(): MeterProviderInterface
    {
        $meterProvider = $this->createMock(MeterProviderInterface::class);
        $meterProvider->method('getMeter')->willReturn($this->makeMeter());

        return $meterProvider;
    }

    public function test_register_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        RuntimeMetrics::register($this->makeMeterProvider());
    }

    public function test_instrumentation_name_is_non_empty_string(): void
    {
        $this->assertNotEmpty(RuntimeMetrics::getInstrumentationName());
    }

    public function test_disabled_memory_group_skips_memory_meter(): void
    {
        $requestedNames = [];
        $meterProvider = $this->createMock(MeterProviderInterface::class);
        $meterProvider->method('getMeter')
            ->willReturnCallback(function (string $name) use (&$requestedNames): MeterInterface {
                $requestedNames[] = $name;

                return $this->makeMeter();
            });

        RuntimeMetrics::register($meterProvider, new RuntimeMetricsConfig(['memory']));

        $this->assertNotContains('io.opentelemetry.contrib.php.runtime.memory', $requestedNames);
    }

    public function test_disabled_multiple_groups_skips_their_meters(): void
    {
        $meterProvider = $this->createMock(MeterProviderInterface::class);
        $meterProvider->expects($this->never())->method('getMeter');

        RuntimeMetrics::register($meterProvider, new RuntimeMetricsConfig(['memory', 'gc', 'opcache', 'cpu']));
    }
}
