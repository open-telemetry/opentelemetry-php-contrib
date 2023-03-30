<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\HttpAsyncClient\HttpAsyncClientInstrumentation;
use OpenTelemetry\SDK\Sdk;

if (class_exists(Sdk::class) && Sdk::isInstrumentationDisabled(HttpAsyncClientInstrumentation::NAME) === true) {
    return;
}

HttpAsyncClientInstrumentation::register();
