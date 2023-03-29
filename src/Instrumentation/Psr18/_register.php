<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\Psr18\Psr18Instrumentation;
use OpenTelemetry\SDK\Sdk;

if (Sdk::isInstrumentationDisabled('psr18') === true) {
    return;
}

Psr18Instrumentation::register();
