<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\Wordpress\WordpressInstrumentation;

if (extension_loaded('otel_instrumentation') === true) {
    WordpressInstrumentation::register();
} else {
    trigger_error('The otel_instrumentation extension must be loaded in order to autoload the OpenTelemetry Wordpress auto-instrumentation', E_USER_WARNING);
}
