<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Runtime\Tests\Unit;

use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObservableCounterInterface;
use OpenTelemetry\API\Metrics\ObservableGaugeInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;
use OpenTelemetry\Contrib\Metrics\Runtime\GarbageCollectionMetrics;
use PHPUnit\Framework\TestCase;

class GarbageCollectionMetricsTest extends TestCase
{
    public function test_register_creates_all_instruments(): void
    {
        $meter = $this->createMock(MeterInterface::class);

        $expectedCounters = PHP_VERSION_ID >= 80300 ? 5 : 2;

        $meter->expects($this->exactly($expectedCounters))
            ->method('createObservableCounter')
            ->willReturn($this->createMock(ObservableCounterInterface::class));

        $meter->expects($this->exactly(2))
            ->method('createObservableGauge')
            ->willReturn($this->createMock(ObservableGaugeInterface::class));

        GarbageCollectionMetrics::register($meter);
    }

    public function test_gc_runs_callback_observes_integer(): void
    {
        $capturedCallback = null;

        $callbackStub = $this->createMock(ObservableCallbackInterface::class);
        $counter = $this->createMock(ObservableCounterInterface::class);
        $counter->method('observe')
            ->willReturnCallback(function (callable $cb) use (&$capturedCallback, $callbackStub): ObservableCallbackInterface {
                if ($capturedCallback === null) {
                    $capturedCallback = $cb;
                }

                return $callbackStub;
            });

        $meter = $this->createMock(MeterInterface::class);
        $meter->method('createObservableCounter')->willReturn($counter);
        $meter->method('createObservableGauge')->willReturn($this->createMock(ObservableGaugeInterface::class));

        GarbageCollectionMetrics::register($meter);

        assert($capturedCallback !== null);

        $observer = $this->createMock(ObserverInterface::class);
        $observer->expects($this->once())
            ->method('observe')
            ->with($this->isType('int'));

        $capturedCallback($observer);
    }

    public function test_gc_timing_metrics_registered_on_php83(): void
    {
        if (PHP_VERSION_ID < 80300) {
            $this->markTestSkipped('GC timing metrics require PHP 8.3+');
        }

        $meter = $this->createMock(MeterInterface::class);
        $meter->expects($this->exactly(5))
            ->method('createObservableCounter')
            ->willReturn($this->createMock(ObservableCounterInterface::class));
        $meter->method('createObservableGauge')
            ->willReturn($this->createMock(ObservableGaugeInterface::class));

        GarbageCollectionMetrics::register($meter);
    }

    public function test_gc_timing_callback_observes_float(): void
    {
        if (PHP_VERSION_ID < 80300) {
            $this->markTestSkipped('GC timing metrics require PHP 8.3+');
        }

        $callbacks = [];
        $callbackStub = $this->createMock(ObservableCallbackInterface::class);
        $counter = $this->createMock(ObservableCounterInterface::class);
        $counter->method('observe')
            ->willReturnCallback(function (callable $cb) use (&$callbacks, $callbackStub): ObservableCallbackInterface {
                $callbacks[] = $cb;

                return $callbackStub;
            });

        $meter = $this->createMock(MeterInterface::class);
        $meter->method('createObservableCounter')->willReturn($counter);
        $meter->method('createObservableGauge')->willReturn($this->createMock(ObservableGaugeInterface::class));

        GarbageCollectionMetrics::register($meter);

        // callbacks[2] is collector_time (index 0=runs, 1=collected, 2=collector_time)
        $observer = $this->createMock(ObserverInterface::class);
        $observer->expects($this->once())
            ->method('observe')
            ->with($this->isType('float'));

        $callbacks[2]($observer);
    }
}
