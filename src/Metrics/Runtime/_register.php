<?php

declare(strict_types=1);

use Nevay\SPI\ServiceLoader;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Instrumentation;
use OpenTelemetry\Contrib\Metrics\Runtime\RuntimeMetricsInstrumentation;

// @phan-suppress-next-line PhanTemplateTypeConstraintViolation
ServiceLoader::register(Instrumentation::class, RuntimeMetricsInstrumentation::class);
