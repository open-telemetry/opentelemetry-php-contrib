<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Runtime;

use Composer\InstalledVersions;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\SemConv\Version;

class RuntimeMetrics
{
    public const NAME = 'metrics-runtime';

    private const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.runtime';

    /**
     * Register available runtime metric observers on the given meter provider.
     *
     * Each group gets its own meter (io.opentelemetry.contrib.php.runtime.{group}),
     * which allows disabling them via SDK meter configurators or views in OTEL_CONFIG_FILE.
     *
     * Individual groups can also be disabled by passing their names in $disabled.
     * Valid group names: memory, gc, opcache, cpu
     *
     * OPcache and CPU metrics are also skipped when the underlying PHP
     * functions are unavailable, regardless of $disabled.
     *
     * @param list<string> $disabled
     */
    public static function register(MeterProviderInterface $meterProvider, array $disabled = []): void
    {
        $version = InstalledVersions::getPrettyVersion('open-telemetry/opentelemetry-metrics-runtime');
        $schemaUrl = Version::VERSION_1_38_0->url();

        if (!\in_array(MemoryMetrics::GROUP, $disabled, true)) {
            MemoryMetrics::register(
                $meterProvider->getMeter(self::INSTRUMENTATION_NAME . '.memory', $version, $schemaUrl),
            );
        }
        if (!\in_array(GarbageCollectionMetrics::GROUP, $disabled, true)) {
            GarbageCollectionMetrics::register(
                $meterProvider->getMeter(self::INSTRUMENTATION_NAME . '.gc', $version, $schemaUrl),
            );
        }
        if (!\in_array(OpcacheMetrics::GROUP, $disabled, true) && OpcacheMetrics::isAvailable()) {
            OpcacheMetrics::register(
                $meterProvider->getMeter(self::INSTRUMENTATION_NAME . '.opcache', $version, $schemaUrl),
            );
        }
        if (!\in_array(CpuMetrics::GROUP, $disabled, true) && CpuMetrics::isAvailable()) {
            CpuMetrics::register(
                $meterProvider->getMeter(self::INSTRUMENTATION_NAME . '.cpu', $version, $schemaUrl),
            );
        }
    }

    public static function getInstrumentationName(): string
    {
        return self::INSTRUMENTATION_NAME;
    }
}
