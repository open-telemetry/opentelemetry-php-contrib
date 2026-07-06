<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Runtime;

use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;

/**
 * @internal
 */
class MemoryMetrics
{
    public const GROUP = 'memory';

    public static function register(MeterInterface $meter): void
    {
        $meter
            ->createObservableUpDownCounter(
                'php.memory.usage',
                'By',
                'Current memory usage (real allocation from OS)',
            )
            ->observe(static function (ObserverInterface $observer): void {
                $emalloc = memory_get_usage(false);
                $observer->observe($emalloc, ['memory.type' => 'emalloc']);
                $observer->observe(memory_get_usage(true) - $emalloc, ['memory.type' => 'overhead']);
            });

        $meter
            ->createObservableUpDownCounter(
                'php.memory.peak_usage',
                'By',
                'Peak memory usage since script start',
            )
            ->observe(static function (ObserverInterface $observer): void {
                $emalloc = memory_get_peak_usage(false);
                $observer->observe($emalloc, ['memory.type' => 'emalloc']);
                $observer->observe(memory_get_peak_usage(true) - $emalloc, ['memory.type' => 'overhead']);
            });

        $meter
            ->createObservableGauge(
                'php.memory.limit',
                'By',
                'Memory limit configured in php.ini (-1 means unlimited)',
            )
            ->observe(static function (ObserverInterface $observer): void {
                $limit = ini_get('memory_limit') ?: '-1';
                $observer->observe(ini_parse_quantity($limit));
            });
    }

    public static function parseMemoryLimit(string $limit): int
    {
        return ini_parse_quantity($limit !== '' ? $limit : '-1');
    }
}
