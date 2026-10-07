--TEST--
Test inject context to cake logger
--FILE--

<?php
use Cake\Log\Log;
use OpenTelemetry\API\Globals;

putenv('OTEL_PHP_AUTOLOAD_ENABLED=true');
putenv('OTEL_LOGS_EXPORTER=console');
putenv('OTEL_TRACES_EXPORTER=none');
putenv('OTEL_METRICS_EXPORTER=none');
putenv('OTEL_PHP_DETECTORS=none');
putenv('OTEL_PHP_PSR3_MODE=inject');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

Log::setConfig('default', [
    'className' => \Cake\Log\Engine\ConsoleLog::class,
    'levels' => ['warning', 'info'],
]);

$span = Globals::tracerProvider()->getTracer('demo')->spanBuilder('root')->startSpan();
$scope = $span->activate();

$input = require(__DIR__ . '/input.php');

Log::info($input['message_with_interpolation'], $input['context']);

$scope->detach();
$span->end();
?>

--EXPECTF--
%s info: hello world trace_id=%s span_id=%s