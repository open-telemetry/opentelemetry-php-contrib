<?php

/**
 * This file is loaded by PHPUnit (per phpunit bootstrap configuration).
 * Any OTEL_* env vars can be set in phpunit.xml.dist before this point,
 * so we can re-trigger autoloading the SDK.
 */

declare(strict_types=1);

use Nevay\SPI\ServiceLoader;
use OpenTelemetry\API\Configuration\Config\ComponentProvider;
use OpenTelemetry\SDK\SdkAutoloader;

/**
 * For tests that wish to capture in-memory data when using the OTEL_CONFIG_FILE.
 */
ServiceLoader::register(ComponentProvider::class, \OpenTelemetry\TestUtils\ComponentProvider\InMemoryLogRecordExporter::class);
ServiceLoader::register(ComponentProvider::class, \OpenTelemetry\TestUtils\ComponentProvider\InMemoryMetricsExporter::class);
ServiceLoader::register(ComponentProvider::class, \OpenTelemetry\TestUtils\ComponentProvider\InMemorySpanExporter::class);

/**
 * Capture for later observation via {@see \OpenTelemetry\SDK\Common\Export\InMemoryStorageManager}
 */
putenv('OTEL_TRACES_EXPORTER=memory');
putenv('OTEL_METRICS_EXPORTER=memory');
putenv('OTEL_LOGS_EXPORTER=memory');

/**
 * Ensure that the memory based exporters are populated,
 * as the default batch processor keeps these values buffered.
 */
putenv('OTEL_PHP_LOGS_PROCESSOR=simple');
putenv('OTEL_PHP_TRACES_PROCESSOR=simple');

// Pre-requisite for the SDK autoloader.
putenv('OTEL_PHP_AUTOLOAD_ENABLED=true');

// Finally, ensure that the SDK loads with the updated env vars and SPI test components.
SdkAutoloader::autoload();
