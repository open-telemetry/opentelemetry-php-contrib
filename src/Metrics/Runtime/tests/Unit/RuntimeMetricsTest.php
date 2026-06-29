<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Runtime\Tests\Unit;

use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\ObservableCounterInterface;
use OpenTelemetry\API\Metrics\ObservableGaugeInterface;
use OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface;
use OpenTelemetry\Contrib\Metrics\Runtime\RuntimeMetrics;
use PHPUnit\Framework\TestCase;

class RuntimeMetricsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('OTEL_PHP_DISABLED_METRICS');
    }

    public function test_register_does_not_throw(): void
    {
        $meter = $this->createMock(MeterInterface::class);
        $meter->method('createObservableUpDownCounter')
            ->willReturn($this->createMock(ObservableUpDownCounterInterface::class));
        $meter->method('createObservableGauge')
            ->willReturn($this->createMock(ObservableGaugeInterface::class));
        $meter->method('createObservableCounter')
            ->willReturn($this->createMock(ObservableCounterInterface::class));

        $this->expectNotToPerformAssertions();
        RuntimeMetrics::register($meter);
    }

    public function test_instrumentation_name_is_non_empty_string(): void
    {
        $this->assertNotEmpty(RuntimeMetrics::getInstrumentationName());
    }

    public function test_disabled_memory_group_skips_memory_instruments(): void
    {
        putenv('OTEL_PHP_DISABLED_METRICS=memory');

        $meter = $this->createMock(MeterInterface::class);
        $meter->expects($this->never())->method('createObservableUpDownCounter');
        $meter->method('createObservableGauge')
            ->willReturn($this->createMock(ObservableGaugeInterface::class));
        $meter->method('createObservableCounter')
            ->willReturn($this->createMock(ObservableCounterInterface::class));

        RuntimeMetrics::register($meter);
    }

    public function test_disabled_gc_group_skips_gc_instruments(): void
    {
        putenv('OTEL_PHP_DISABLED_METRICS=gc,cpu');

        $meter = $this->createMock(MeterInterface::class);
        $meter->method('createObservableUpDownCounter')
            ->willReturn($this->createMock(ObservableUpDownCounterInterface::class));
        $meter->method('createObservableGauge')
            ->willReturn($this->createMock(ObservableGaugeInterface::class));
        $meter->expects($this->never())->method('createObservableCounter');

        RuntimeMetrics::register($meter);
    }

    public function test_disabled_multiple_groups_via_comma_list(): void
    {
        putenv('OTEL_PHP_DISABLED_METRICS=memory,gc,opcache,cpu');

        $meter = $this->createMock(MeterInterface::class);
        $meter->expects($this->never())->method('createObservableUpDownCounter');
        $meter->expects($this->never())->method('createObservableCounter');
        $meter->expects($this->never())->method('createObservableGauge');

        RuntimeMetrics::register($meter);
    }

    public function test_disabled_env_is_case_insensitive(): void
    {
        putenv('OTEL_PHP_DISABLED_METRICS=Memory,GC,Opcache,CPU');

        $meter = $this->createMock(MeterInterface::class);
        $meter->expects($this->never())->method('createObservableUpDownCounter');
        $meter->expects($this->never())->method('createObservableCounter');
        $meter->expects($this->never())->method('createObservableGauge');

        RuntimeMetrics::register($meter);
    }
}
