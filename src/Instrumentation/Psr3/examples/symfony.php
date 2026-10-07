<?php

declare(strict_types=1);

use OpenTelemetry\API\Globals;

putenv('OTEL_PHP_AUTOLOAD_ENABLED=true');
putenv('OTEL_LOGS_EXPORTER=console');
putenv('OTEL_TRACES_EXPORTER=none');
putenv('OTEL_METRICS_EXPORTER=none');
putenv('OTEL_PHP_PSR3_OBSERVE_ALL_METHODS=true');
//putenv('OTEL_PHP_PSR3_MODE=inject');
putenv('OTEL_PHP_PSR3_MODE=export');

require __DIR__ . '/../vendor/autoload.php';

/**
 * Example of using the opentelemetry extension with symfony to:
 * - export logs in OTLP format (if mode=`export`)
 * - inject trace_id/span_id into context (if mode=`inject`)
 */

$logger = new \Symfony\Component\Console\Logger\ConsoleLogger(new Symfony\Component\Console\Output\StreamOutput(STDOUT));

$span = Globals::tracerProvider()->getTracer('demo')->spanBuilder('root')->startSpan();
$scope = $span->activate();

$logger->warning('hello world', ['foo' => 'bar', 'exception' => new \Exception('kaboom', 500, new \RuntimeException('kablam'))]);
$logger->error('hello, OpenTelemetry trace_id={trace_id} span_id={span_id}');

$scope->detach();
$span->end();
