<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Runtime\Tests\Unit;

use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObservableCounterInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;
use OpenTelemetry\Contrib\Metrics\Runtime\CpuMetrics;
use PHPUnit\Framework\TestCase;

class CpuMetricsTest extends TestCase
{
    public function test_is_available_returns_bool(): void
    {
        $this->assertIsBool(CpuMetrics::isAvailable());
    }

    public function test_register_creates_all_instruments(): void
    {
        if (!CpuMetrics::isAvailable()) {
            $this->markTestSkipped('getrusage() not available on this platform');
        }

        $meter = $this->createMock(MeterInterface::class);

        $meter->expects($this->exactly(3))
            ->method('createObservableCounter')
            ->willReturn($this->createMock(ObservableCounterInterface::class));

        $meter->expects($this->once())
            ->method('batchObserve')
            ->willReturn($this->createMock(ObservableCallbackInterface::class));

        CpuMetrics::register($meter);
    }

    public function test_cpu_time_callback_observes_user_and_system_modes(): void
    {
        if (!CpuMetrics::isAvailable()) {
            $this->markTestSkipped('getrusage() not available on this platform');
        }

        $capturedCallback = null;

        $meter = $this->createMock(MeterInterface::class);
        $meter->method('createObservableCounter')->willReturn($this->createMock(ObservableCounterInterface::class));
        $meter->method('batchObserve')
            ->willReturnCallback(function (callable $cb) use (&$capturedCallback): ObservableCallbackInterface {
                $capturedCallback = $cb;

                return $this->createMock(ObservableCallbackInterface::class);
            });

        CpuMetrics::register($meter);

        assert($capturedCallback !== null);

        $cpuObserver = $this->createMock(ObserverInterface::class);
        $cpuObserver->expects($this->exactly(2))
            ->method('observe')
            ->with(
                $this->isType('float'),
                $this->logicalOr(
                    $this->equalTo(['cpu.mode' => 'user']),
                    $this->equalTo(['cpu.mode' => 'system']),
                ),
            );

        $vcsObserver = $this->createMock(ObserverInterface::class);
        $ivcsObserver = $this->createMock(ObserverInterface::class);

        $capturedCallback($cpuObserver, $vcsObserver, $ivcsObserver);
    }
}
