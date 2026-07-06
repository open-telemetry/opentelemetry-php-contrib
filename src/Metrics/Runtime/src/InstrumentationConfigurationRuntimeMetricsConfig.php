<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Runtime;

use function in_array;
use OpenTelemetry\API\Configuration\ConfigEnv\EnvComponentLoader;
use OpenTelemetry\API\Configuration\ConfigEnv\EnvComponentLoaderRegistry;
use OpenTelemetry\API\Configuration\ConfigEnv\EnvResolver;
use OpenTelemetry\API\Configuration\Context;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\InstrumentationConfiguration;

/**
 * Loads {@see RuntimeMetricsConfig} from OTEL_PHP_DISABLED_INSTRUMENTATIONS,
 * using "metrics-runtime-{group}" names (metrics-runtime-memory,
 * metrics-runtime-gc, metrics-runtime-opcache, metrics-runtime-cpu),
 * or "metrics-runtime" to disable the whole package.
 *
 * @implements EnvComponentLoader<InstrumentationConfiguration>
 * @internal
 */
final class InstrumentationConfigurationRuntimeMetricsConfig implements EnvComponentLoader
{
    private const GROUPS = [
        MemoryMetrics::GROUP,
        GarbageCollectionMetrics::GROUP,
        OpcacheMetrics::GROUP,
        CpuMetrics::GROUP,
    ];

    public function load(EnvResolver $env, EnvComponentLoaderRegistry $registry, Context $context): InstrumentationConfiguration
    {
        $disabledInstrumentations = $env->list('OTEL_PHP_DISABLED_INSTRUMENTATIONS') ?? [];

        if (in_array('all', $disabledInstrumentations, true) || in_array(RuntimeMetrics::NAME, $disabledInstrumentations, true)) {
            return new RuntimeMetricsConfig(self::GROUPS);
        }

        $disabled = [];
        foreach (self::GROUPS as $group) {
            if (in_array(RuntimeMetrics::NAME . '-' . $group, $disabledInstrumentations, true)) {
                $disabled[] = $group;
            }
        }

        return new RuntimeMetricsConfig($disabled);
    }

    public function name(): string
    {
        return RuntimeMetricsConfig::class;
    }
}
