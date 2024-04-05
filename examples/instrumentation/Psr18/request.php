<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use OpenTelemetry\API\Instrumentation;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\SDK\Common\Export\Stream\StreamTransportFactory;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

$transport = (new StreamTransportFactory())->create('php://output', 'application/json');
$exporter = new ConsoleSpanExporter($transport);

$tracerProvider =  new TracerProvider(
    new BatchSpanProcessor($exporter, ClockFactory::getDefault()),
    new AlwaysOnSampler(),
    ResourceInfoFactory::emptyResource(),
);

$scope = Instrumentation\Configurator::create()
    ->withTracerProvider($tracerProvider)
    ->withPropagator(TraceContextPropagator::getInstance())
    ->activate();

try {
    $client = new Client();

    $response = $client->sendRequest(new Request('GET', 'https://postman-echo.com/get'));
    echo json_encode(json_decode($response->getBody()->getContents()), JSON_PRETTY_PRINT), PHP_EOL;
} finally {
    $scope->detach();
    $tracerProvider->shutdown();
}
