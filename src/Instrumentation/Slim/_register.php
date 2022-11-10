<?php

declare(strict_types=1);

assert(extension_loaded('otel_instrumentation'));

\OpenTelemetry\Contrib\Instrumentation\Slim\SlimInstrumentation::register();
