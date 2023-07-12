--TEST--
Test generating OTLP from monolog logger
--FILE--

<?php
use OpenTelemetry\API\Globals;

putenv('OTEL_PHP_AUTOLOAD_ENABLED=true');
putenv('OTEL_LOGS_EXPORTER=console');
putenv('OTEL_TRACES_EXPORTER=none');
putenv('OTEL_METRICS_EXPORTER=none');
putenv('OTEL_PHP_DETECTORS=none');
putenv('OTEL_PHP_PSR3_MODE=export');
putenv('OTEL_PHP_PSR3_OBSERVE_ALL_METHODS=true');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$logger = new \Monolog\Logger('test', [new \Monolog\Handler\NullHandler()]);

$span = Globals::tracerProvider()->getTracer('demo')->spanBuilder('root')->startSpan();
$scope = $span->activate();

$input = require(__DIR__ . '/input.php');

$logger->warning($input['message'], $input['context']);

$scope->detach();
$span->end();
?>

--EXPECTF--
{
    "resource": {
        "attributes": [],
        "dropped_attributes_count": 0
    },
    "scopes": [
        {
            "name": "psr3",
            "version": null,
            "attributes": [],
            "dropped_attributes_count": 0,
            "schema_url": null,
            "logs": [
                {
                    "timestamp": null,
                    "observed_timestamp": %d,
                    "severity_number": 0,
                    "severity_text": null,
                    "body": {
                        "foo": "bar",
                        "exception": {}
                    },
                    "trace_id": "%s",
                    "span_id": "%s",
                    "trace_flags": 1,
                    "attributes": [],
                    "dropped_attributes_count": 0
                }
            ]
        }
    ]
}