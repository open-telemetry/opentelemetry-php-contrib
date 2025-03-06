--TEST--
Test disabling the psr3 instrumentation
--DESCRIPTION--
No otlp logs should be generated, but logging from the psr-3 logger should still be emitted.
No context will be injected into the log message.
--INI--
error_reporting = E_ALL & ~E_DEPRECATED
--FILE--

<?php
use Cake\Log\Log;
use OpenTelemetry\API\Globals;

putenv('OTEL_PHP_AUTOLOAD_ENABLED=true');
putenv('OTEL_LOGS_EXPORTER=console');
putenv('OTEL_TRACES_EXPORTER=none');
putenv('OTEL_METRICS_EXPORTER=none');
putenv('OTEL_PHP_DISABLED_INSTRUMENTATIONS=psr3');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

var_dump(\OpenTelemetry\SDK\Sdk::isInstrumentationDisabled('psr3'));

$logger = new \Apix\Log\Logger\Stream(STDOUT);

$span = Globals::tracerProvider()->getTracer('demo')->spanBuilder('root')->startSpan();
$scope = $span->activate();

$logger->info('Goodbye, otel: traceId={traceId} spanId={spanId}');

$scope->detach();
$span->end();
?>

--EXPECTF--
bool(true)
[%s] INFO Goodbye, otel: traceId={traceId} spanId={spanId}