<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Runtime\Tests\Unit;

use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObservableCounterInterface;
use OpenTelemetry\API\Metrics\ObservableGaugeInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;
use OpenTelemetry\Contrib\Metrics\Runtime\GarbageCollectionMetrics;
use const PHP_VERSION_ID;
use PHPUnit\Framework\TestCase;

class GarbageCollectionMetricsTest extends TestCase
{
    public function test_register_creates_all_instruments(): void
    {
        $meter = $this->createMock(MeterInterface::class);

        $meter->expects($this->exactly(5))
            ->method('createObservableCounter')
            ->willReturn($this->createMock(ObservableCounterInterface::class));

        $meter->expects($this->exactly(3))
            ->method('createObservableGauge')
            ->willReturn($this->createMock(ObservableGaugeInterface::class));

        $meter->expects($this->exactly(1))
            ->method('batchObserve')
            ->willReturn($this->createMock(ObservableCallbackInterface::class));

        GarbageCollectionMetrics::register($meter);
    }

    public function test_gc_runs_callback_observes_integer(): void
    {
        $capturedCallback = null;

        $meter = $this->createMock(MeterInterface::class);
        $meter->method('createObservableCounter')->willReturn($this->createMock(ObservableCounterInterface::class));
        $meter->method('createObservableGauge')->willReturn($this->createMock(ObservableGaugeInterface::class));
        $meter->method('batchObserve')
            ->willReturnCallback(function (callable $cb) use (&$capturedCallback): ObservableCallbackInterface {
                if ($capturedCallback === null) {
                    $capturedCallback = $cb;
                }

                return $this->createMock(ObservableCallbackInterface::class);
            });

        GarbageCollectionMetrics::register($meter);

        assert($capturedCallback !== null);

        $runsObs = $this->createMock(ObserverInterface::class);
        $runsObs->expects($this->once())->method('observe')->with($this->isType('int'));

        $capturedCallback(
            $runsObs,
            $this->createMock(ObserverInterface::class),
            $this->createMock(ObserverInterface::class),
            $this->createMock(ObserverInterface::class),
            $this->createMock(ObserverInterface::class),
            $this->createMock(ObserverInterface::class),
            $this->createMock(ObserverInterface::class),
            $this->createMock(ObserverInterface::class),
        );
    }

    public function test_gc_timing_callback_observes_float(): void
    {
        if (PHP_VERSION_ID < 80300) {
            $this->markTestSkipped('GC timing metrics require PHP 8.3+');
        }

        $callback = null;
        $meter = $this->createMock(MeterInterface::class);
        $meter->method('createObservableCounter')->willReturn($this->createMock(ObservableCounterInterface::class));
        $meter->method('createObservableGauge')->willReturn($this->createMock(ObservableGaugeInterface::class));
        $meter->method('batchObserve')
            ->willReturnCallback(function (callable $cb) use (&$callback): ObservableCallbackInterface {
                $callback = $cb;

                return $this->createMock(ObservableCallbackInterface::class);
            });

        GarbageCollectionMetrics::register($meter);

        $collectorObs = $this->createMock(ObserverInterface::class);
        $collectorObs->expects($this->once())->method('observe')->with($this->isType('float'));

        $this->assertNotNull($callback);

        $callback(
            $this->createMock(ObserverInterface::class),
            $this->createMock(ObserverInterface::class),
            $this->createMock(ObserverInterface::class),
            $this->createMock(ObserverInterface::class),
            $collectorObs,
            $this->createMock(ObserverInterface::class),
            $this->createMock(ObserverInterface::class),
            $this->createMock(ObserverInterface::class),
        );
    }
}
