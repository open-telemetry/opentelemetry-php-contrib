<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\MongoDB\MongoDBInstrumentation;
use OpenTelemetry\SDK\Sdk;

if (class_exists(Sdk::class) && Sdk::isInstrumentationDisabled(MongoDBInstrumentation::NAME) === true) {
    return;
}

MongoDBInstrumentation::register();
