<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelInstrumentation;
use OpenTelemetry\SDK\Sdk;

(function () {
    $instrumentation = new LaravelInstrumentation();

    if (class_exists(Sdk::class) && Sdk::isInstrumentationDisabled($instrumentation->getName()) === true) {
        return;
    }

    if (extension_loaded('opentelemetry') === false) {
        trigger_error('The opentelemetry extension must be loaded in order to autoload the OpenTelemetry Laravel auto-instrumentation', E_USER_WARNING);

        return;
    }

    $instrumentation->activate();
})();
