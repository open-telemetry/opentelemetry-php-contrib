<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\Twig\TwigInstrumentation;

if (extension_loaded('opentelemetry') && class_exists(TwigInstrumentation::class)) {
    if (getenv('OTEL_PHP_INSTRUMENTATION_TWIG_ENABLED') !== 'false') {
        TwigInstrumentation::register();
    }
}
