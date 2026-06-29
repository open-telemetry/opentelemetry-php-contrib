<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Runtime;

use OpenTelemetry\API\Metrics\MeterInterface;

class RuntimeMetrics
{
    public const NAME = 'metrics-runtime';

    private const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.runtime';
    private const ENV_DISABLED_METRICS = 'OTEL_PHP_DISABLED_METRICS';

    /**
     * Register available runtime metric observers on the given meter.
     *
     * Individual groups can be disabled via the OTEL_PHP_DISABLED_METRICS
     * environment variable as a comma-separated list of group names:
     *   OTEL_PHP_DISABLED_METRICS=opcache,cpu
     *
     * Valid group names: memory, gc, opcache, cpu
     *
     * OPcache and CPU metrics are also skipped when the underlying PHP
     * functions are unavailable, regardless of this setting.
     */
    public static function register(MeterInterface $meter): void
    {
        $disabled = self::disabledGroups();

        if (!in_array(MemoryMetrics::GROUP, $disabled, true)) {
            MemoryMetrics::register($meter);
        }

        if (!in_array(GarbageCollectionMetrics::GROUP, $disabled, true)) {
            GarbageCollectionMetrics::register($meter);
        }

        if (!in_array(OpcacheMetrics::GROUP, $disabled, true) && OpcacheMetrics::isAvailable()) {
            OpcacheMetrics::register($meter);
        }

        if (!in_array(CpuMetrics::GROUP, $disabled, true) && CpuMetrics::isAvailable()) {
            CpuMetrics::register($meter);
        }
    }

    public static function getInstrumentationName(): string
    {
        return self::INSTRUMENTATION_NAME;
    }

    /** @return list<string> */
    private static function disabledGroups(): array
    {
        $value = getenv(self::ENV_DISABLED_METRICS);
        if ($value === false || $value === '') {
            return [];
        }

        return array_map('trim', explode(',', strtolower($value)));
    }
}
