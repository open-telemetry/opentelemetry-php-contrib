<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Propagation\ServerTiming\ServerTimingPropagator;
use OpenTelemetry\SDK\Registry;

if (!class_exists(Registry::class)) {
    return;
}

Registry::registerResponsePropagator(
    'servertiming',
    ServerTimingPropagator::getInstance()
);
