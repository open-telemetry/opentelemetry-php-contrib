<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Runtime\Tests\Unit;

use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObservableCounterInterface;
use OpenTelemetry\API\Metrics\ObservableGaugeInterface;
use OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface;
use OpenTelemetry\Contrib\Metrics\Runtime\OpcacheMetrics;
use PHPUnit\Framework\TestCase;

class OpcacheMetricsTest extends TestCase
{
    public function test_is_available_reflects_actual_opcache_state(): void
    {
        $expected = function_exists('opcache_get_status') && opcache_get_status(false) !== false;
        $this->assertSame($expected, OpcacheMetrics::isAvailable());
    }

    public function test_register_creates_all_instruments(): void
    {
        $meter = $this->createMock(MeterInterface::class);

        // 3 memory + 2 interned_strings = 5 UpDownCounters
        $meter->expects($this->exactly(5))
            ->method('createObservableUpDownCounter')
            ->willReturn($this->createMock(ObservableUpDownCounterInterface::class));

        // hit_rate + cached_scripts + interned_strings.count = 3 Gauges
        $meter->expects($this->exactly(3))
            ->method('createObservableGauge')
            ->willReturn($this->createMock(ObservableGaugeInterface::class));

        // hits + misses = 2 Counters
        $meter->expects($this->exactly(2))
            ->method('createObservableCounter')
            ->willReturn($this->createMock(ObservableCounterInterface::class));

        $meter->expects($this->once())
            ->method('batchObserve')
            ->willReturn($this->createMock(ObservableCallbackInterface::class));

        OpcacheMetrics::register($meter);
    }
}
