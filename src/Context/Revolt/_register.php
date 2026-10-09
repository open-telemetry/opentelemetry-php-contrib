<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Context\Revolt;

use function class_exists;
use Nevay\SPI\ServiceLoader;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Instrumentation;

if (!class_exists(ServiceLoader::class)) {
    return;
}

ServiceLoader::register(Instrumentation::class, RevoltMetrics::class);
