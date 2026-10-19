<?php

/**
 * This file must *only* be used to register SPI components, as it
 * will be pruned automatically via the tbachert/spi plugin.
 */

declare(strict_types=1);

use Nevay\SPI\ServiceLoader;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Instrumentation;
use OpenTelemetry\Contrib\Instrumentation\Psr18\Psr18Instrumentation;

if (!class_exists(ServiceLoader::class)) {
    return;
}

ServiceLoader::register(Instrumentation::class, Psr18Instrumentation::class);
