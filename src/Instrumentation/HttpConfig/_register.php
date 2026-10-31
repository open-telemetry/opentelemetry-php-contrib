<?php

/**
 * This file must *only* be used to register SPI components, as it
 * will be pruned automatically via the tbachert/spi plugin.
 */

declare(strict_types=1);

use Nevay\SPI\ServiceLoader;
use OpenTelemetry\API\Configuration\Config\ComponentProvider;
use OpenTelemetry\API\Configuration\ConfigEnv\EnvComponentLoader;

if (!class_exists(ServiceLoader::class)) {
    return;
}

ServiceLoader::register(EnvComponentLoader::class, \OpenTelemetry\Contrib\Instrumentation\HttpConfig\ConfigEnv\InstrumentationConfigurationHttpConfig::class);

ServiceLoader::register(ComponentProvider::class, \OpenTelemetry\Contrib\Instrumentation\HttpConfig\Config\InstrumentationConfigurationHttpConfig::class);
ServiceLoader::register(ComponentProvider::class, \OpenTelemetry\Contrib\Instrumentation\HttpConfig\Config\UriSanitizerDefault::class);
ServiceLoader::register(ComponentProvider::class, \OpenTelemetry\Contrib\Instrumentation\HttpConfig\Config\UriSanitizerRedactQueryStringValues::class);
ServiceLoader::register(ComponentProvider::class, \OpenTelemetry\Contrib\Instrumentation\HttpConfig\Config\UriSanitizerRedactUserInfo::class);
