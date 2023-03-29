<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\PDO\PDOInstrumentation;

if (extension_loaded('otel_instrumentation') === false) {
    trigger_error('The otel_instrumentation extension must be loaded in order to autoload the OpenTelemetry PDO auto-instrumentation', E_USER_WARNING);

    return;
}

PDOInstrumentation::register();
