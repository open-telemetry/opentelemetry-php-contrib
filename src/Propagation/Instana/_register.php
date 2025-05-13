<?php

declare(strict_types=1);
use OpenTelemetry\Contrib\Propagation\Instana\InstanaMultiPropagator;
use OpenTelemetry\SDK\Registry;

if (!class_exists(Registry::class)) {
    return;
}
Registry::registerTextMapPropagator(
    'instana',
    InstanaMultiPropagator::getInstance()
);
