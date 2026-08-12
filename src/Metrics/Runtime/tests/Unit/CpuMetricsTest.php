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

        $contextSwitchesObserver = $this->createMock(ObserverInterface::class);

        $pagingFaultsObserver = $this->createMock(ObserverInterface::class);

        $capturedCallback($cpuObserver, $contextSwitchesObserver, $pagingFaultsObserver);
    }

    public function test_context_switches_callback_observes_voluntary_and_involuntary_types(): void
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

        $contextSwitchesObserver = $this->createMock(ObserverInterface::class);
        $contextSwitchesObserver->expects($this->atMost(2))
            ->method('observe')
            ->with(
                $this->isType('int'),
                $this->logicalOr(
                    $this->equalTo(['process.context_switch.type' => 'voluntary']),
                    $this->equalTo(['process.context_switch.type' => 'involuntary']),
                ),
            );

        $pagingFaultsObserver = $this->createMock(ObserverInterface::class);

        $capturedCallback($cpuObserver, $contextSwitchesObserver, $pagingFaultsObserver);
    }

    public function test_paging_faults_callback_observes_minor_and_major_types(): void
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

        $contextSwitchesObserver = $this->createMock(ObserverInterface::class);

        $pagingFaultsObserver = $this->createMock(ObserverInterface::class);
        $pagingFaultsObserver->expects($this->atMost(2))
            ->method('observe')
            ->with(
                $this->isType('int'),
                $this->logicalOr(
                    $this->equalTo(['system.paging.fault.type' => 'minor']),
                    $this->equalTo(['system.paging.fault.type' => 'major']),
                ),
            );

        $capturedCallback($cpuObserver, $contextSwitchesObserver, $pagingFaultsObserver);
    }
}
