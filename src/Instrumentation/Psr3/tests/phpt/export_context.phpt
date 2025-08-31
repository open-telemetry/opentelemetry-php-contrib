--TEST--
Test generating otel LogRecord with complex message context
--FILE--

<?php
use OpenTelemetry\API\Globals;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

putenv('OTEL_PHP_AUTOLOAD_ENABLED=true');
putenv('OTEL_LOGS_EXPORTER=console');
putenv('OTEL_TRACES_EXPORTER=none');
putenv('OTEL_METRICS_EXPORTER=none');
putenv('OTEL_PHP_DETECTORS=none');
putenv('OTEL_PHP_PSR3_MODE=export');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$logger = new class implements LoggerInterface{
    use LoggerTrait;
    public function log($level, string|\Stringable $message, array $context = []): void {}
};

$span = Globals::tracerProvider()->getTracer('demo')->spanBuilder('root')->startSpan();
$scope = $span->activate();

$context = [
    's' => 'string',
    'i' => 1234,
    'l' => 3.14159,
    't' => true,
    'f' => false,
    'stringable' => new class() implements \Stringable {
        public function __toString(): string
        {
            return 'some_string';
        }
    },
    'j' => new class() implements JsonSerializable {
        public function jsonSerialize(): array
        {
            return ['key' => 'value'];
        }
    },
    'array' => ['a', 'b', 'c'],
    'exception' => new \Exception('my_exception'),
    'bin' => \Symfony\Component\Uid\Uuid::v4()->toBinary(),
];


$logger->info('test message', $context);

$scope->detach();
$span->end();
?>

--EXPECTF--
{
    "resource": {
        "attributes": %A
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
                    "body": "test message",
                    "trace_id": "%s",
                    "span_id": "%s",
                    "trace_flags": 1,
                    "attributes": {
                        "s": "string",
                        "i": 1234,
                        "l": 3.14159,
                        "t": true,
                        "f": false,
                        "stringable": "some_string",
                        "j": {
                            "key": "value"
                        },
                        "array": [
                            "a",
                            "b",
                            "c"
                        ],
                        "exception": {
                            "message": "my_exception",
                            "code": 0,
                            "file": "Standard input code",
                            "line": %d,
                            "trace": [],
                            "previous": []
                        },
                        "bin": "%s"
                    },
                    "dropped_attributes_count": 0
                }
            ]
        }
    ]
}