<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Propagation\TraceResponse\TraceResponsePropagator;
use OpenTelemetry\SDK\Registry;

if (!class_exists(Registry::class)) {
    return;
}

Registry::registerResponsePropagator(
    'traceresponse',
    TraceResponsePropagator::getInstance()
);
