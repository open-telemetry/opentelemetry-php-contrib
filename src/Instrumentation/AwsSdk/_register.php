<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\AwsSdk\AwsSdkInstrumentation;
use OpenTelemetry\SDK\Sdk;

if (class_exists(Sdk::class)
    && Sdk::isInstrumentationDisabled(AwsSdkInstrumentation::NAME)) {
    return;
}

if (!extension_loaded('opentelemetry')) {
    trigger_error(
        'The opentelemetry extension must be loaded to use the AWS SDK auto‑instrumentation',
        E_USER_WARNING
    );

    return;
}

AwsSdkInstrumentation::register();
