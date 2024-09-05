<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\ExtRdKafka\ExtRdKafkaInstrumentation;
use OpenTelemetry\SDK\Sdk;

if (class_exists(Sdk::class) && Sdk::isInstrumentationDisabled(ExtRdKafkaInstrumentation::NAME) === true) {
    return;
}

if (extension_loaded('opentelemetry') === false) {
    trigger_error('The opentelemetry extension must be loaded in order to autoload the OpenTelemetry ext-rdkafka auto-instrumentation', E_USER_WARNING);

    return;
}

if (!extension_loaded('rdkafka')) {
    return;
}

ExtRdKafkaInstrumentation::register();
