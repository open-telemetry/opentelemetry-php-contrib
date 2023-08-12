<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\Psr3\Psr3Instrumentation;
use OpenTelemetry\SDK\Sdk;

if (class_exists(Sdk::class) && Sdk::isInstrumentationDisabled(Psr3Instrumentation::NAME) === true) {
    return;
}

if (extension_loaded('opentelemetry') === false) {
    trigger_error('The opentelemetry extension must be loaded in order to autoload the OpenTelemetry PSR-3 auto-instrumentation', E_USER_WARNING);

    return;
}

Psr3Instrumentation::register();
