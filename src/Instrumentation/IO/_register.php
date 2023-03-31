<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\IO\IOInstrumentation;
use OpenTelemetry\SDK\Sdk;

if (class_exists(Sdk::class) && Sdk::isInstrumentationDisabled(IOInstrumentation::NAME) === true) {
    return;
}

if (extension_loaded('otel_instrumentation') === false) {
    trigger_error('The otel_instrumentation extension must be loaded in order to autoload the OpenTelemetry IO auto-instrumentation', E_USER_WARNING);

    return;
}

IOInstrumentation::register();
