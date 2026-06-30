<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Runtime\Tests\Unit;

use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObservableGaugeInterface;
use OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;
use OpenTelemetry\Contrib\Metrics\Runtime\MemoryMetrics;
use PHPUnit\Framework\TestCase;

class MemoryMetricsTest extends TestCase
{
    public function test_register_creates_all_instruments(): void
    {
        $meter = $this->createMock(MeterInterface::class);

        $meter->expects($this->exactly(2))
            ->method('createObservableUpDownCounter')
            ->willReturn($this->createMock(ObservableUpDownCounterInterface::class));

        $meter->expects($this->once())
            ->method('createObservableGauge')
            ->willReturn($this->createMock(ObservableGaugeInterface::class));

        MemoryMetrics::register($meter);
    }

    public function test_memory_usage_callback_observes_real_and_emalloc(): void
    {
        $capturedCallbacks = [];

        $callbackStub = $this->createMock(ObservableCallbackInterface::class);
        $upDownCounter = $this->createMock(ObservableUpDownCounterInterface::class);
        $upDownCounter->method('observe')
            ->willReturnCallback(function (callable $cb) use (&$capturedCallbacks, $callbackStub): ObservableCallbackInterface {
                $capturedCallbacks[] = $cb;

                return $callbackStub;
            });

        $meter = $this->createMock(MeterInterface::class);
        $meter->method('createObservableUpDownCounter')->willReturn($upDownCounter);
        $meter->method('createObservableGauge')->willReturn($this->createMock(ObservableGaugeInterface::class));

        MemoryMetrics::register($meter);

        $observer = $this->createMock(ObserverInterface::class);
        $observer->expects($this->exactly(2))->method('observe');

        $capturedCallbacks[0]($observer);
    }

    /**
     * @dataProvider memoryLimitProvider
     */
    public function test_memory_limit_parsed_correctly(string $limit, int $expected): void
    {
        $this->assertSame($expected, MemoryMetrics::parseMemoryLimit($limit));
    }

    public static function memoryLimitProvider(): array
    {
        return [
            'megabytes' => ['128M', 128 * 1024 * 1024],
            'kilobytes' => ['512K', 512 * 1024],
            'gigabytes' => ['2G', 2 * 1024 * 1024 * 1024],
            'bytes' => ['1048576', 1048576],
            'unlimited' => ['-1', -1],
        ];
    }
}
