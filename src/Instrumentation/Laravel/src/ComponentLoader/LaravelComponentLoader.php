<?php declare(strict_types=1);
namespace OpenTelemetry\Contrib\Instrumentation\Laravel\ComponentLoader;

use OpenTelemetry\API\Configuration\ConfigEnv\EnvComponentLoader;
use OpenTelemetry\API\Configuration\ConfigEnv\EnvComponentLoaderRegistry;
use OpenTelemetry\API\Configuration\ConfigEnv\EnvResolver;
use OpenTelemetry\API\Configuration\Context;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\InstrumentationConfiguration;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelConfiguration;
use function in_array;

/**
 * @implements EnvComponentLoader<InstrumentationConfiguration>
 */
final class LaravelComponentLoader implements EnvComponentLoader {

    public function load(EnvResolver $env, EnvComponentLoaderRegistry $registry, Context $context): InstrumentationConfiguration {
        $disabledInstrumentations = $env->list('OTEL_PHP_DISABLED_INSTRUMENTATIONS');

        return new LaravelConfiguration(
            enabled: !$disabledInstrumentations || $disabledInstrumentations !== ['all'] && !in_array('laravel', $disabledInstrumentations, true),
            traceCliEnabled: $env->bool('OTEL_PHP_TRACE_CLI_ENABLED') ?? false,
        );
    }

    public function name(): string {
        return LaravelConfiguration::class;
    }
}