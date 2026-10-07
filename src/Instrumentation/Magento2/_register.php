<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\Magento2\Magento2Instrumentation;
use OpenTelemetry\SDK\Sdk;

if (class_exists(Sdk::class) && Sdk::isInstrumentationDisabled(Magento2Instrumentation::NAME) === true) {
    return;
}

Magento2Instrumentation::register();
