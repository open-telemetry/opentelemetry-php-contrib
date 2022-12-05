<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use Http\Adapter\Guzzle7\Client;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use OpenTelemetry\API\Common\Instrumentation;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\SDK\Common\Export\Stream\StreamTransport;
use OpenTelemetry\SDK\Common\Log\LoggerHolder;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Psr\Log\LogLevel;

require_once dirname(__DIR__) . '/vendor/autoload.php';

LoggerHolder::set(
    new Logger('otel-php', [new StreamHandler(STDOUT, LogLevel::DEBUG)])
);

$transport = new StreamTransport(fopen('php://stdout', 'a'), 'application/json');
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

$root = $tracerProvider->getTracer('async-requests-demo')->spanBuilder('root')->startSpan();
$rootScope = $root->activate();

try {
    $requests = [
        new Request('GET', 'https://postman-echo.com/get'),
        new Request('POST', 'https://postman-echo.com/post'),
        new Request('GET', 'https://httpbin.org/does-not-exist'),
        new Request('GET', 'https://httpbin.org/get'),
    ];
    $client = new Client();
    $promises = [];
    foreach ($requests as $request) {
        $promises[] = [$request, $client->sendAsyncRequest($request)];
    }
    foreach ($promises as [$request, $promise]) {
        try {
            $response = $promise->wait();
            echo sprintf('[%d] ', $response->getStatusCode()) . json_encode(json_decode($response->getBody()->getContents()), JSON_PRETTY_PRINT), PHP_EOL;
        } catch (\Exception $e) {
            var_dump($e->getMessage());
        }
    }

    $root->end();
} finally {
    $rootScope->detach();
    $scope->detach();
    $tracerProvider->shutdown();
}
