<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\Psr15\Psr15Instrumentation;
use OpenTelemetry\SDK\Sdk;

if (class_exists(Sdk::class) && Sdk::isInstrumentationDisabled(Psr15Instrumentation::NAME) === true) {
    return;
}

Psr15Instrumentation::register();
