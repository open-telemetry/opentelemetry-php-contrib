<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\Psr14\Psr14Instrumentation;
use OpenTelemetry\SDK\Sdk;

if (class_exists(Sdk::class) && Sdk::isInstrumentationDisabled(Psr14Instrumentation::NAME) === true) {
    return;
}

if (extension_loaded('opentelemetry') === false) {
    trigger_error('The opentelemetry extension must be loaded in order to autoload the OpenTelemetry PSR-14 auto-instrumentation', E_USER_WARNING);

    return;
}

Psr14Instrumentation::register();
