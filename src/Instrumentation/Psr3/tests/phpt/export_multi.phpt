--TEST--
Test generating OTLP from multiple psr3 loggers
--INI--
error_reporting = E_ALL & ~E_DEPRECATED
--FILE--

<?php
use OpenTelemetry\API\Globals;

putenv('OTEL_PHP_AUTOLOAD_ENABLED=true');
putenv('OTEL_LOGS_EXPORTER=console');
putenv('OTEL_TRACES_EXPORTER=none');
putenv('OTEL_METRICS_EXPORTER=none');
putenv('OTEL_PHP_DETECTORS=none');
putenv('OTEL_PHP_PSR3_MODE=export');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$apix = new \Apix\Log\Logger\Stream(STDOUT);
$symfony = new \Symfony\Component\Console\Logger\ConsoleLogger(new Symfony\Component\Console\Output\StreamOutput(STDOUT));
$monolog = new \Monolog\Logger('test', [new \Monolog\Handler\StreamHandler(STDOUT)]);
$yii = $logger = (new \Yiisoft\Log\Logger([
           (new \Yiisoft\Log\StreamTarget(STDOUT))->setExportInterval(1)
       ]))->setFlushInterval(1);

$span = Globals::tracerProvider()->getTracer('demo')->spanBuilder('root')->startSpan();
$scope = $span->activate();

$apix->warning('hello apix');
$symfony->warning('hello symfony');
$monolog->warning('hello monolog');
$yii->warning('hello yii');

$scope->detach();
$span->end();
?>

--EXPECTF--
[%s] WARNING hello apix
[warning] hello symfony
[%s] test.WARNING: hello monolog [] []
%s [warning][application] hello yii

Message context:

%A

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
            "schema_url": "https:\/\/opentelemetry.io\/schemas\/%s",
            "logs": [
                {
                    "timestamp": null,
                    "observed_timestamp": %d,
                    "severity_number": %d,
                    "severity_text": null,
                    "body": "hello apix",
                    "trace_id": "%s",
                    "span_id": "%s",
                    "trace_flags": 1,
                    "attributes": [],
                    "dropped_attributes_count": 0
                },
                {
                    "timestamp": null,
                    "observed_timestamp": %d,
                    "severity_number": %d,
                    "severity_text": null,
                    "body": "hello symfony",
                    "trace_id": "%s",
                    "span_id": "%s",
                    "trace_flags": 1,
                    "attributes": [],
                    "dropped_attributes_count": 0
                },
                {
                    "timestamp": null,
                    "observed_timestamp": %s,
                    "severity_number": %d,
                    "severity_text": null,
                    "body": "hello monolog",
                    "trace_id": "%s",
                    "span_id": "%s",
                    "trace_flags": 1,
                    "attributes": [],
                    "dropped_attributes_count": 0
                },
                {
                    "timestamp": null,
                    "observed_timestamp": %d,
                    "severity_number": %d,
                    "severity_text": null,
                    "body": "hello yii",
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