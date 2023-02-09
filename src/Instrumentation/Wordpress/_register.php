<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\Wordpress\WordpressInstrumentation;

assert(extension_loaded('otel_instrumentation'));

WordpressInstrumentation::register();
