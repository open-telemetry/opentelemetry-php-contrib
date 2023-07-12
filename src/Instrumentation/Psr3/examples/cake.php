<?php

declare(strict_types=1);

use Cake\Log\Log;
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
 * Example of using the opentelemetry extension to:
 * - export logs in OTLP format (if mode=`export`)
 * - inject traceId/spanId into context (if mode=`inject`) - NB that cake's built-in formatters only use context for interpolation
 */

Log::setConfig('default', [
    'className' => \Cake\Log\Engine\ConsoleLog::class,
    'levels' => ['warning', 'info'],
]);

$span = Globals::tracerProvider()->getTracer('demo')->spanBuilder('root')->startSpan();
$scope = $span->activate();

Log::warning('hello world', ['foo' => 'bar', 'exception' => new \Exception('kaboom', 500, new \RuntimeException('kablam'))]);
Log::info('hello, OpenTelemetry traceId={traceId}');

$scope->detach();
$span->end();
