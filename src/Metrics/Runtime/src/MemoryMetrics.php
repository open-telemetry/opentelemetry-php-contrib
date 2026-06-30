<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Runtime;

use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;

class MemoryMetrics
{
    public const GROUP = 'memory';

    public static function register(MeterInterface $meter): void
    {
        $meter
            ->createObservableUpDownCounter(
                'process.runtime.php.memory.usage',
                'By',
                'Current memory usage (real allocation from OS)',
            )
            ->observe(static function (ObserverInterface $observer): void {
                $observer->observe(memory_get_usage(true), ['memory.type' => 'real']);
                $observer->observe(memory_get_usage(false), ['memory.type' => 'emalloc']);
            });

        $meter
            ->createObservableUpDownCounter(
                'process.runtime.php.memory.peak_usage',
                'By',
                'Peak memory usage since script start',
            )
            ->observe(static function (ObserverInterface $observer): void {
                $observer->observe(memory_get_peak_usage(true), ['memory.type' => 'real']);
                $observer->observe(memory_get_peak_usage(false), ['memory.type' => 'emalloc']);
            });

        $meter
            ->createObservableGauge(
                'process.runtime.php.memory.limit',
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
