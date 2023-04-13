<?php

declare(strict_types=1);

/**
 * This example uses OpenTelemetry SDK autoloading to configure a LoggerProvider, which will be used by the
 * monolog handler by default.
 * Logs are protobuf-encoded and send to an OpenTelemetry collector.
 */

use Monolog\Level;
use Monolog\Logger;
use OpenTelemetry\Contrib\Logs\Monolog\Handler;
use Psr\Log\LogLevel;

putenv('OTEL_PHP_AUTOLOAD_ENABLED=true');
putenv('OTEL_METRICS_EXPORTER=none');
putenv('OTEL_LOGS_EXPORTER=otlp');
putenv('OTEL_LOGS_PROCESSOR=batch');
putenv('OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf');
putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://collector:4318');

require dirname(__DIR__) . '/vendor/autoload.php';

$handler = new Handler(
    OpenTelemetry\API\Common\Instrumentation\Globals::loggerProvider(),
    LogLevel::INFO, //or `Logger::INFO`, or `Level::Info` depending on monolog version
    true,
);
$logger = new Logger(
    'example',
    [$handler],
);

$logger->info('hello, otel');
$logger->error('something went wrong', [
    'foo' => 'bar',
    'exception' => new Exception('something went wrong', 500, new Exception('the first exception', 99)),
]);
