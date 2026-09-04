<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Logs\Monolog\ConfigEnv;

use OpenTelemetry\API\Configuration\ConfigEnv\EnvComponentLoader;
use OpenTelemetry\API\Configuration\ConfigEnv\EnvComponentLoaderRegistry;
use OpenTelemetry\API\Configuration\ConfigEnv\EnvResolver;
use OpenTelemetry\API\Configuration\Context;
use OpenTelemetry\Contrib\Logs\Monolog\Handler;
use OpenTelemetry\Contrib\Logs\Monolog\HandlerConfiguration;

/**
 * @implements EnvComponentLoader<HandlerConfiguration>
 */
final class HandlerEnvLoader implements EnvComponentLoader
{
    public function load(EnvResolver $env, EnvComponentLoaderRegistry $registry, Context $context): HandlerConfiguration
    {
        return new HandlerConfiguration(
            mode: $env->enum(Handler::OTEL_PHP_MONOLOG_ATTRIB_MODE, Handler::MODES) ?? Handler::DEFAULT_MODE,
        );
    }

    public function name(): string
    {
        return HandlerConfiguration::class;
    }
}
