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

ServiceLoader::register(ComponentProvider::class, \OpenTelemetry\TestUtils\ComponentProvider\InMemoryLogRecordExporter::class);
ServiceLoader::register(ComponentProvider::class, \OpenTelemetry\TestUtils\ComponentProvider\InMemoryMetricsExporter::class);
ServiceLoader::register(ComponentProvider::class, \OpenTelemetry\TestUtils\ComponentProvider\InMemorySpanExporter::class);

putenv('OTEL_PHP_AUTOLOAD_ENABLED=true');

// Finally, ensure that the SDK loads with the updated env vars and SPI test components.
SdkAutoloader::autoload();
