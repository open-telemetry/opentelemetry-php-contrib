<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\ExtAmqp\ExtAmqpInstrumentation;
use OpenTelemetry\SDK\Sdk;

if (class_exists(Sdk::class) && Sdk::isInstrumentationDisabled(ExtAmqpInstrumentation::NAME) === true) {
    return;
}

if (extension_loaded('opentelemetry') === false) {
    trigger_error('The opentelemetry extension must be loaded in order to autoload the OpenTelemetry ext-amqp auto-instrumentation', E_USER_WARNING);

    return;
}

if (!extension_loaded('amqp')) {
    return;
}

ExtAmqpInstrumentation::register();
