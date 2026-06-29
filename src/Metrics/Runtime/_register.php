<?php

declare(strict_types=1);

use OpenTelemetry\API\Globals;
use OpenTelemetry\Contrib\Metrics\Runtime\RuntimeMetrics;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SemConv\Version;

if (class_exists(Sdk::class) && Sdk::isInstrumentationDisabled(RuntimeMetrics::NAME) === true) {
    return;
}

RuntimeMetrics::register(
    Globals::meterProvider()->getMeter(
        RuntimeMetrics::getInstrumentationName(),
        null,
        Version::VERSION_1_38_0->url(),
    )
);
