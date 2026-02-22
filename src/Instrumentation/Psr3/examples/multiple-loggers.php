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

$symfony = new \Symfony\Component\Console\Logger\ConsoleLogger(new Symfony\Component\Console\Output\StreamOutput(STDOUT));
$monolog = new \Monolog\Logger('test', [new \Monolog\Handler\StreamHandler(STDOUT)]);
$yii = new \Yiisoft\Log\Logger([new \Yiisoft\Log\StreamTarget(STDOUT)]);

$span = Globals::tracerProvider()->getTracer('demo')->spanBuilder('root')->startSpan();
$scope = $span->activate();

$symfony->warning('hello symfony');
$monolog->warning('hello monolog');
$yii->warning('hello yii');

$scope->detach();
$span->end();
