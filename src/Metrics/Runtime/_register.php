<?php

declare(strict_types=1);

use Nevay\SPI\ServiceLoader;
use OpenTelemetry\API\Configuration\ConfigEnv\EnvComponentLoader;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Instrumentation;
use OpenTelemetry\Contrib\Metrics\Runtime\InstrumentationConfigurationRuntimeMetricsConfig;
use OpenTelemetry\Contrib\Metrics\Runtime\RuntimeMetricsInstrumentation;

// @phan-suppress-next-line PhanTemplateTypeConstraintViolation
ServiceLoader::register(Instrumentation::class, RuntimeMetricsInstrumentation::class);
// @phan-suppress-next-line PhanTemplateTypeConstraintViolation
ServiceLoader::register(EnvComponentLoader::class, InstrumentationConfigurationRuntimeMetricsConfig::class);
