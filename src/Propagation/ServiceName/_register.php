<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Propagation\ServiceName\ServiceNamePropagator;
use OpenTelemetry\SDK\Registry;

if (!class_exists(Registry::class)) {
    return;
}

Registry::registerTextMapPropagator(
    'service-name',
    ServiceNamePropagator::getInstance()
);
