<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Runtime;

use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;

class OpcacheMetrics
{
    public const GROUP = 'opcache';

    public static function isAvailable(): bool
    {
        if (!function_exists('opcache_get_status')) {
            return false;
        }

        // opcache_get_status() returns false when OPcache is loaded but disabled
        // (e.g. opcache.enable_cli=0 in CLI context, or opcache.enable=0)
        return opcache_get_status(false) !== false;
    }

    public static function register(MeterInterface $meter): void
    {
        $memoryUsed = $meter->createObservableUpDownCounter(
            'process.runtime.php.opcache.memory_used',
            'By',
            'OPcache memory used by cached scripts',
        );
        $memoryFree = $meter->createObservableUpDownCounter(
            'process.runtime.php.opcache.memory_free',
            'By',
            'OPcache memory free',
        );
        $memoryWasted = $meter->createObservableUpDownCounter(
            'process.runtime.php.opcache.memory_wasted',
            'By',
            'OPcache memory wasted (fragmented, not usable without restart)',
        );
        $hitRate = $meter->createObservableGauge(
            'process.runtime.php.opcache.hit_rate',
            '%',
            'OPcache hit rate percentage',
        );
        $hits = $meter->createObservableCounter(
            'process.runtime.php.opcache.hits',
            '{hit}',
            'Total OPcache hits',
        );
        $misses = $meter->createObservableCounter(
            'process.runtime.php.opcache.misses',
            '{miss}',
            'Total OPcache misses',
        );
        $cachedScripts = $meter->createObservableGauge(
            'process.runtime.php.opcache.cached_scripts',
            '{script}',
            'Number of scripts currently cached in OPcache',
        );
        $internedStringsMemoryUsed = $meter->createObservableUpDownCounter(
            'process.runtime.php.opcache.interned_strings.memory_used',
            'By',
            'Memory used by OPcache interned strings',
        );
        $internedStringsMemoryFree = $meter->createObservableUpDownCounter(
            'process.runtime.php.opcache.interned_strings.memory_free',
            'By',
            'Memory free in OPcache interned strings buffer',
        );
        $internedStringsCount = $meter->createObservableGauge(
            'process.runtime.php.opcache.interned_strings.count',
            '{string}',
            'Number of interned strings currently stored in OPcache',
        );

        $meter->batchObserve(
            static function (
                ObserverInterface $memUsedObs,
                ObserverInterface $memFreeObs,
                ObserverInterface $memWastedObs,
                ObserverInterface $hitRateObs,
                ObserverInterface $hitsObs,
                ObserverInterface $missesObs,
                ObserverInterface $cachedScriptsObs,
                ObserverInterface $internedMemUsedObs,
                ObserverInterface $internedMemFreeObs,
                ObserverInterface $internedCountObs,
            ): void {
                $status = opcache_get_status(false);
                if ($status === false) {
                    return;
                }
                $memUsedObs->observe((int) $status['memory_usage']['used_memory']);
                $memFreeObs->observe((int) $status['memory_usage']['free_memory']);
                $memWastedObs->observe((int) $status['memory_usage']['wasted_memory']);
                $hitRateObs->observe((float) $status['opcache_statistics']['opcache_hit_rate']);
                $hitsObs->observe((int) $status['opcache_statistics']['hits']);
                $missesObs->observe((int) $status['opcache_statistics']['misses']);
                $cachedScriptsObs->observe((int) $status['opcache_statistics']['num_cached_scripts']);
                $internedMemUsedObs->observe((int) $status['interned_strings_usage']['used_memory']);
                $internedMemFreeObs->observe((int) $status['interned_strings_usage']['free_memory']);
                $internedCountObs->observe((int) $status['interned_strings_usage']['number_of_strings']);
            },
            $memoryUsed,
            $memoryFree,
            $memoryWasted,
            $hitRate,
            $hits,
            $misses,
            $cachedScripts,
            $internedStringsMemoryUsed,
            $internedStringsMemoryFree,
            $internedStringsCount,
        );
    }
}
