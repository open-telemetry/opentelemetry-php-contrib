<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\Slim\SlimInstrumentation;

if (extension_loaded('otel_instrumentation') === false) {
    trigger_error('The otel_instrumentation extension must be loaded in order to autoload the OpenTelemetry Slim Framework auto-instrumentation', E_USER_WARNING);

    return;
}

SlimInstrumentation::register();
