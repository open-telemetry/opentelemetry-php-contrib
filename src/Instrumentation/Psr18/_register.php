<?php

/**
 * This file must *only* be used to register SPI components, as it
 * will be pruned automatically via the tbachert/spi plugin.
 */

declare(strict_types=1);

use Nevay\SPI\ServiceLoader;
use OpenTelemetry\API\Configuration\Config\ComponentProvider;
use OpenTelemetry\API\Configuration\ConfigEnv\EnvComponentLoader;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Instrumentation;
use OpenTelemetry\Contrib\Instrumentation\Psr18\Configuration;
use OpenTelemetry\Contrib\Instrumentation\Psr18\Psr18Instrumentation;

if (!class_exists(ServiceLoader::class)) {
    return;
}

ServiceLoader::register(Instrumentation::class, Psr18Instrumentation::class);
ServiceLoader::register(ComponentProvider::class, Configuration\Config\InstrumentationConfigurationPsr18::class);
ServiceLoader::register(EnvComponentLoader::class, Configuration\ConfigEnv\InstrumentationConfigurationPsr18::class);
