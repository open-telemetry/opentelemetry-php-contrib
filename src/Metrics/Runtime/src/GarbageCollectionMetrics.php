<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Runtime;

use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;

class GarbageCollectionMetrics
{
    public const GROUP = 'gc';

    public static function register(MeterInterface $meter): void
    {
        $meter
            ->createObservableCounter(
                'process.runtime.php.gc.runs',
                '{runs}',
                'Total number of garbage collection cycles run',
            )
            ->observe(static function (ObserverInterface $observer): void {
                $observer->observe(gc_status()['runs']);
            });

        $meter
            ->createObservableCounter(
                'process.runtime.php.gc.collected',
                '{objects}',
                'Total number of objects collected by the garbage collector',
            )
            ->observe(static function (ObserverInterface $observer): void {
                $observer->observe(gc_status()['collected']);
            });

        $meter
            ->createObservableGauge(
                'process.runtime.php.gc.threshold',
                '{objects}',
                'Number of roots needed to trigger a garbage collection cycle',
            )
            ->observe(static function (ObserverInterface $observer): void {
                $observer->observe(gc_status()['threshold']);
            });

        $meter
            ->createObservableGauge(
                'process.runtime.php.gc.roots',
                '{objects}',
                'Current number of objects in the root buffer',
            )
            ->observe(static function (ObserverInterface $observer): void {
                $observer->observe(gc_status()['roots']);
            });

        // Timing metrics available since PHP 8.3
        if (PHP_VERSION_ID >= 80300) {
            $meter
                ->createObservableCounter(
                    'process.runtime.php.gc.collector_time',
                    's',
                    'Cumulative time spent in the garbage collector',
                )
                ->observe(static function (ObserverInterface $observer): void {
                    /** @var array<string, int|float> $status */
                    $status = gc_status();
                    // @phan-suppress-next-line PhanTypeInvalidDimOffset, PhanTypeMismatchArgument -- fields added in PHP 8.3
                    $observer->observe($status['collector_time']);
                });

            $meter
                ->createObservableCounter(
                    'process.runtime.php.gc.destructor_time',
                    's',
                    'Cumulative time spent running destructors during GC',
                )
                ->observe(static function (ObserverInterface $observer): void {
                    /** @var array<string, int|float> $status */
                    $status = gc_status();
                    // @phan-suppress-next-line PhanTypeInvalidDimOffset, PhanTypeMismatchArgument -- fields added in PHP 8.3
                    $observer->observe($status['destructor_time']);
                });

            $meter
                ->createObservableCounter(
                    'process.runtime.php.gc.free_time',
                    's',
                    'Cumulative time spent freeing memory during GC',
                )
                ->observe(static function (ObserverInterface $observer): void {
                    /** @var array<string, int|float> $status */
                    $status = gc_status();
                    // @phan-suppress-next-line PhanTypeInvalidDimOffset, PhanTypeMismatchArgument -- fields added in PHP 8.3
                    $observer->observe($status['free_time']);
                });
        }
    }
}
