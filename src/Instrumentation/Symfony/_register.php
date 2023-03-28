<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\Symfony\SymfonyInstrumentation;

if (extension_loaded('otel_instrumentation') === false) {
    trigger_error('The otel_instrumentation extension must be loaded in order to autoload the OpenTelemetry Laravel auto-instrumentation', E_USER_WARNING);

    return;
}

SymfonyInstrumentation::register();
