<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Runtime;

use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;

/**
 * @internal
 */
class GarbageCollectionMetrics
{
    public const GROUP = 'gc';

    public static function register(MeterInterface $meter): void
    {
        $runs = $meter->createObservableCounter(
            'php.gc.runs',
            '{run}',
            'Total number of garbage collection cycles run',
        );
        $collected = $meter->createObservableCounter(
            'php.gc.collected',
            '{object}',
            'Total number of objects collected by the garbage collector',
        );
        $threshold = $meter->createObservableGauge(
            'php.gc.threshold',
            '{object}',
            'Number of roots needed to trigger a garbage collection cycle',
        );
        $roots = $meter->createObservableGauge(
            'php.gc.roots',
            '{object}',
            'Current number of objects in the root buffer',
        );

        $meter->batchObserve(
            static function (
                ObserverInterface $runsObs,
                ObserverInterface $collectedObs,
                ObserverInterface $thresholdObs,
                ObserverInterface $rootsObs,
            ): void {
                $status = gc_status();
                $runsObs->observe($status['runs']);
                $collectedObs->observe($status['collected']);
                $thresholdObs->observe($status['threshold']);
                $rootsObs->observe($status['roots']);
            },
            $runs,
            $collected,
            $threshold,
            $roots,
        );

        // Timing metrics available since PHP 8.3
        if (PHP_VERSION_ID >= 80300) {
            $collectorTime = $meter->createObservableCounter(
                'php.gc.collector_time',
                's',
                'Cumulative time spent in the garbage collector',
            );
            $destructorTime = $meter->createObservableCounter(
                'php.gc.destructor_time',
                's',
                'Cumulative time spent running destructors during GC',
            );
            $freeTime = $meter->createObservableCounter(
                'php.gc.free_time',
                's',
                'Cumulative time spent freeing memory during GC',
            );
            $processUptime = $meter->createObservableGauge(
                'process.uptime',
                's',
                'The time the process has been running',
            );

            $meter->batchObserve(
                static function (
                    ObserverInterface $collectorObs,
                    ObserverInterface $destructorObs,
                    ObserverInterface $freeObs,
                    ObserverInterface $uptimeObs,
                ): void {
                    /** @var array<string, int|float> $status */
                    $status = gc_status();
                    // @phan-suppress-next-line PhanTypeInvalidDimOffset, PhanTypeMismatchArgument -- fields added in PHP 8.3
                    $collectorObs->observe($status['collector_time']);
                    // @phan-suppress-next-line PhanTypeInvalidDimOffset, PhanTypeMismatchArgument -- fields added in PHP 8.3
                    $destructorObs->observe($status['destructor_time']);
                    // @phan-suppress-next-line PhanTypeInvalidDimOffset, PhanTypeMismatchArgument -- fields added in PHP 8.3
                    $freeObs->observe($status['free_time']);
                    // @phan-suppress-next-line PhanTypeInvalidDimOffset, PhanTypeMismatchArgument -- fields added in PHP 8.3
                    $uptimeObs->observe($status['application_time']);
                },
                $collectorTime,
                $destructorTime,
                $freeTime,
                $processUptime,
            );
        }
    }
}
