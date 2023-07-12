--TEST--
Test inject context to symfony logger
--FILE--

<?php
use OpenTelemetry\API\Globals;

putenv('OTEL_PHP_AUTOLOAD_ENABLED=true');
putenv('OTEL_LOGS_EXPORTER=console');
putenv('OTEL_TRACES_EXPORTER=none');
putenv('OTEL_METRICS_EXPORTER=none');
putenv('OTEL_PHP_DETECTORS=none');
putenv('OTEL_PHP_PSR3_MODE=inject');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$logger = new \Yiisoft\Log\Logger([new \Yiisoft\Log\StreamTarget(STDOUT)]);

$span = Globals::tracerProvider()->getTracer('demo')->spanBuilder('root')->startSpan();
$scope = $span->activate();

$input = require(__DIR__ . '/input.php');

$logger->warning($input['message_with_interpolation'], $input['context']);

$scope->detach();
$span->end();
?>

--EXPECTF--
%s [warning][%s] hello world traceId=%s spanId=%s

Message context:

foo: 'bar'
exception: RuntimeException: kablam in %s
Stack trace:
#0 %s
#1 %s

Next Exception: kaboom in %s
Stack trace:
#0 %s
#1 %s
traceId: '%s'
spanId: '%s'
time: %d.%d
memory: %d
category: '%s'