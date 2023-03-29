<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\HttpAsyncClient\HttpAsyncClientInstrumentation;
use OpenTelemetry\SDK\Sdk;

if (Sdk::isInstrumentationDisabled('http-async-client') === true) {
    return;
}

HttpAsyncClientInstrumentation::register();
