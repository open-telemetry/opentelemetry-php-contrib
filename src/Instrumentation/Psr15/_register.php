<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\Psr15\Psr15Instrumentation;
use OpenTelemetry\SDK\Sdk;

if (class_exists(Sdk::class) && Sdk::isInstrumentationDisabled(Psr15Instrumentation::NAME) === true) {
    return;
}

if (extension_loaded('otel_instrumentation') === false) {
    trigger_error('The otel_instrumentation extension must be loaded in order to autoload the OpenTelemetry PSR-15 auto-instrumentation', E_USER_WARNING);

    return;
}

Psr15Instrumentation::register();
