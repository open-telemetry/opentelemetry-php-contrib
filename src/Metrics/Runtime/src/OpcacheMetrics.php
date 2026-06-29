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
        $meter
            ->createObservableUpDownCounter(
                'process.runtime.php.opcache.memory_used',
                'By',
                'OPcache memory used by cached scripts',
            )
            ->observe(static function (ObserverInterface $observer): void {
                $status = opcache_get_status(false);
                if ($status === false) {
                    return;
                }
                $observer->observe((int) $status['memory_usage']['used_memory']);
            });

        $meter
            ->createObservableUpDownCounter(
                'process.runtime.php.opcache.memory_free',
                'By',
                'OPcache memory free',
            )
            ->observe(static function (ObserverInterface $observer): void {
                $status = opcache_get_status(false);
                if ($status === false) {
                    return;
                }
                $observer->observe((int) $status['memory_usage']['free_memory']);
            });

        $meter
            ->createObservableUpDownCounter(
                'process.runtime.php.opcache.memory_wasted',
                'By',
                'OPcache memory wasted (fragmented, not usable without restart)',
            )
            ->observe(static function (ObserverInterface $observer): void {
                $status = opcache_get_status(false);
                if ($status === false) {
                    return;
                }
                $observer->observe((int) $status['memory_usage']['wasted_memory']);
            });

        $meter
            ->createObservableGauge(
                'process.runtime.php.opcache.hit_rate',
                '%',
                'OPcache hit rate percentage',
            )
            ->observe(static function (ObserverInterface $observer): void {
                $status = opcache_get_status(false);
                if ($status === false) {
                    return;
                }
                $observer->observe((float) $status['opcache_statistics']['opcache_hit_rate']);
            });

        $meter
            ->createObservableCounter(
                'process.runtime.php.opcache.hits',
                '{hits}',
                'Total OPcache hits',
            )
            ->observe(static function (ObserverInterface $observer): void {
                $status = opcache_get_status(false);
                if ($status === false) {
                    return;
                }
                $observer->observe((int) $status['opcache_statistics']['hits']);
            });

        $meter
            ->createObservableCounter(
                'process.runtime.php.opcache.misses',
                '{misses}',
                'Total OPcache misses',
            )
            ->observe(static function (ObserverInterface $observer): void {
                $status = opcache_get_status(false);
                if ($status === false) {
                    return;
                }
                $observer->observe((int) $status['opcache_statistics']['misses']);
            });

        $meter
            ->createObservableGauge(
                'process.runtime.php.opcache.cached_scripts',
                '{scripts}',
                'Number of scripts currently cached in OPcache',
            )
            ->observe(static function (ObserverInterface $observer): void {
                $status = opcache_get_status(false);
                if ($status === false) {
                    return;
                }
                $observer->observe((int) $status['opcache_statistics']['num_cached_scripts']);
            });

        $meter
            ->createObservableUpDownCounter(
                'process.runtime.php.opcache.interned_strings.memory_used',
                'By',
                'Memory used by OPcache interned strings',
            )
            ->observe(static function (ObserverInterface $observer): void {
                $status = opcache_get_status(false);
                if ($status === false) {
                    return;
                }
                $observer->observe((int) $status['interned_strings_usage']['used_memory']);
            });

        $meter
            ->createObservableUpDownCounter(
                'process.runtime.php.opcache.interned_strings.memory_free',
                'By',
                'Memory free in OPcache interned strings buffer',
            )
            ->observe(static function (ObserverInterface $observer): void {
                $status = opcache_get_status(false);
                if ($status === false) {
                    return;
                }
                $observer->observe((int) $status['interned_strings_usage']['free_memory']);
            });

        $meter
            ->createObservableGauge(
                'process.runtime.php.opcache.interned_strings.count',
                '{strings}',
                'Number of interned strings currently stored in OPcache',
            )
            ->observe(static function (ObserverInterface $observer): void {
                $status = opcache_get_status(false);
                if ($status === false) {
                    return;
                }
                $observer->observe((int) $status['interned_strings_usage']['number_of_strings']);
            });
    }
}
