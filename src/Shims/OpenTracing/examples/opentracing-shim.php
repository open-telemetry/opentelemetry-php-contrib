<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Shim\OpenTracing\Tracer;
use OpenTelemetry\SDK\Common\Util\ShutdownHandler;
use OpenTelemetry\SDK\Trace\TracerProviderFactory;
use OpenTracing as API;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Example of using opentracing-shim to emit OpenTelemetry traces via the OpenTracing API.
 */

//putenv('OTEL_TRACES_EXPORTER=console'); //uncomment to display spans to console
putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://collector:4318');
putenv('OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf');
$tracerProvider = (new TracerProviderFactory())->create();

$tracer = new Tracer($tracerProvider);
API\GlobalTracer::set($tracer);

//ensure pending traces are sent on shutdown
ShutdownHandler::register([$tracerProvider, 'shutdown']);

//mock request headers, containing w3c distributed trace headers
$headers = [
    'foo' => 'bar',
    'traceparent' => '00-ff000000000000000000000000000041-ff00000000000041-01',
    'tracestate' => 'foo=bar',
];

$parent = $tracer->extract(API\Formats\TEXT_MAP, $headers);

$scope = $tracer->startActiveSpan('shim-demo', ['child_of' => $parent, 'tags' => ['foo' => 'bar']]);
$span = $scope->getSpan();
$span->overwriteOperationName('shim-demo-updated');
$span->addBaggageItem('shim', 'baggage-1');
$span->setTag('attr_one', 'foo');
$span->setTag('attr_two', false);
$span->setTag(API\Tags\SPAN_KIND, 'server');
$span->log(['event' => 'something', 'foo' => 'bar', 'baz' => 'bat']);
$span->log(['event' => 'something.else', 'exception' => new \RuntimeException('uh-oh', 500)]);

//get the active span
$active = $tracer->getActiveSpan();
assert($active === $span);

//retrieve baggage
$baggage = $span->getBaggageItem('shim');

//child span with implicit parent (active span)
$child_one = $tracer->startSpan('child-1');
//child span with explicit parent (another span which is not the active span)
$child_two = $tracer->startSpan('child-2', ['child_of' => $child_one]);

//generate outbound trace context from a span
$carrier = [];
$tracer->inject($child_two->getContext(), API\Formats\TEXT_MAP, $carrier);
//var_dump($carrier);

//generate outbound trace context from active span
$carrier = [];
$tracer->inject($tracer->getActiveSpan()->getContext(), API\Formats\HTTP_HEADERS, $carrier);
//var_dump($carrier);

$child_two->finish();
$child_one->finish();

$span->finish();
$scope->close();
