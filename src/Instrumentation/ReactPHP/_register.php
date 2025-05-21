<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\ReactPHP\ReactPHPInstrumentation;
use OpenTelemetry\SDK\Sdk;

if (class_exists(Sdk::class) && Sdk::isInstrumentationDisabled(ReactPHPInstrumentation::NAME) === true) {
    return;
}

if (extension_loaded('opentelemetry') === false) {
    trigger_error('The opentelemetry extension must be loaded in order to autoload the OpenTelemetry ReactPHP HTTP auto-instrumentation', E_USER_WARNING);

    return;
}

ReactPHPInstrumentation::register();
