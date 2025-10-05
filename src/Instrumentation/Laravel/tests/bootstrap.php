<?php

/**
 * This file is loaded by PHPUnit (per phpunit bootstrap configuration).
 * Necessary OTEL_* env vars have been set in phpunit.xml.dist at this point,
 * so we can re-trigger autoloading the SDK.
 */

declare(strict_types=1);

use Nevay\SPI\ServiceLoader;
use OpenTelemetry\API\Configuration\Config\ComponentProvider;
use OpenTelemetry\SDK\SdkAutoloader;

ServiceLoader::register(ComponentProvider::class, \OpenTelemetry\Contrib\Instrumentation\Laravel\ComponentProvider\LaravelComponentProvider::class);

ServiceLoader::register(ComponentProvider::class, \OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\ComponentProvider\LogRecordExporterInMemory::class);
ServiceLoader::register(ComponentProvider::class, \OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\ComponentProvider\SpanExporterInMemory::class);

// Finally, ensure that the SDK loads with the updated env vars and SPI test components.
SdkAutoloader::autoload();
