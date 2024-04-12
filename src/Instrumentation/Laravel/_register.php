<?php

declare(strict_types=1);

use Nevay\SPI\ServiceLoader;
use OpenTelemetry\API\Instrumentation\InstrumentationInterface;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelInstrumentation;

// In case spi plugin has not been allowed in composer allow-plugins (root-level).
ServiceLoader::register(InstrumentationInterface::class, LaravelInstrumentation::class);
