<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\HttpAsyncClient\HttpAsyncClientInstrumentation;
use OpenTelemetry\SDK\Sdk;

if (class_exists(Sdk::class) && Sdk::isInstrumentationDisabled(HttpAsyncClientInstrumentation::NAME) === true) {
    return;
}

if (extension_loaded('otel_instrumentation') === false) {
    trigger_error('The otel_instrumentation extension must be loaded in order to autoload the OpenTelemetry HTTPlug async auto-instrumentation', E_USER_WARNING);

    return;
}

HttpAsyncClientInstrumentation::register();
