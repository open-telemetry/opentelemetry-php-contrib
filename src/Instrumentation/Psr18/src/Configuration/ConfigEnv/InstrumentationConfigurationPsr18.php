<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Psr18\Configuration\ConfigEnv;

use OpenTelemetry\API\Configuration\ConfigEnv\EnvComponentLoader;
use OpenTelemetry\API\Configuration\ConfigEnv\EnvComponentLoaderRegistry;
use OpenTelemetry\API\Configuration\ConfigEnv\EnvResolver;
use OpenTelemetry\API\Configuration\Context;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\InstrumentationConfiguration;
use OpenTelemetry\Contrib\Instrumentation\Psr18\Configuration\Psr18Configuration;
use Override;

/**
 * @implements EnvComponentLoader<InstrumentationConfiguration>
 */
final class InstrumentationConfigurationPsr18 implements EnvComponentLoader
{

    #[Override]
    public function load(EnvResolver $env, EnvComponentLoaderRegistry $registry, Context $context): InstrumentationConfiguration
    {
        $disabledInstrumentations = $env->list('OTEL_PHP_DISABLED_INSTRUMENTATIONS');

        return new Psr18Configuration(
            enabled: !$disabledInstrumentations || $disabledInstrumentations !== ['all'] && !in_array('psr18', $disabledInstrumentations, true),
        );
    }

    #[Override]
    public function name(): string
    {
        return Psr18Configuration::class;
    }
}
