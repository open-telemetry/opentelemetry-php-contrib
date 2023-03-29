<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\Psr15\Psr15Instrumentation;
use OpenTelemetry\SDK\Sdk;

if (Sdk::isInstrumentationDisabled('psr15') === true) {
    return;
}

Psr15Instrumentation::register();
