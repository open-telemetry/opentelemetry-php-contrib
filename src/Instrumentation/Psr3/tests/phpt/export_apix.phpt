--TEST--
Test generating OTLP from apix logger
--FILE--

<?php
use Cake\Log\Log;
use OpenTelemetry\API\Globals;

putenv('OTEL_PHP_AUTOLOAD_ENABLED=true');
putenv('OTEL_LOGS_EXPORTER=console');
putenv('OTEL_TRACES_EXPORTER=none');
putenv('OTEL_METRICS_EXPORTER=none');
putenv('OTEL_PHP_DETECTORS=none');
putenv('OTEL_PHP_PSR3_MODE=export');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$logger = new \Apix\Log\Logger\Stream(STDOUT);

$span = Globals::tracerProvider()->getTracer('demo')->spanBuilder('root')->startSpan();
$scope = $span->activate();

$input = require(__DIR__ . '/input.php');

$logger->info($input['message'], $input['context']);

$scope->detach();
$span->end();
?>

--EXPECTF--
[%s] INFO hello world

{
    "resource": {
        "attributes": [],
        "dropped_attributes_count": 0
    },
    "scopes": [
        {
            "name": "io.opentelemetry.contrib.php.psr3",
            "version": null,
            "attributes": [],
            "dropped_attributes_count": 0,
            "schema_url": "https:\/\/opentelemetry.io\/schemas\/1.24.0",
            "logs": [
                {
                    "timestamp": null,
                    "observed_timestamp": %d,
                    "severity_number": %d,
                    "severity_text": null,
                    "body": "hello world",
                    "trace_id": "%s",
                    "span_id": "%s",
                    "trace_flags": 1,
                    "attributes": {
                        "foo": "bar",
                        "exception": {
                            "message": "kaboom",
                            "code": 500,
                            "file": "%s",
                            "line": %d,
                            "trace": [
                                {
                                    "file": "%s",
                                    "line": %d,
                                    "function": "%s"
                                }
                            ],
                            "previous": {
                                "message": "kablam",
                                "code": 0,
                                "file": "%s",
                                "line": %d,
                                "trace": [
                                    {
                                        "file": "%s",
                                        "line": %d,
                                        "function": "%s"
                                    }
                                ],
                                "previous": []
                            }
                        }
                    },
                    "dropped_attributes_count": 0
                }
            ]
        }
    ]
}