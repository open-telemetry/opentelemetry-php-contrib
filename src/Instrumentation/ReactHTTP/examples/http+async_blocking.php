<?php

declare(strict_types=1);

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\SDK\Common\Export\Stream\StreamTransportFactory;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use function React\Async\await;
use React\Http\Browser;
use React\Http\Message\Request;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$transport = (new StreamTransportFactory())->create('php://output', 'application/json');
$exporter = new ConsoleSpanExporter($transport);

$tracerProvider =  new TracerProvider(
    new BatchSpanProcessor($exporter, Clock::getDefault()),
    new AlwaysOnSampler(),
    ResourceInfoFactory::emptyResource(),
);

Sdk::builder()
    ->setTracerProvider($tracerProvider)
    ->setPropagator(TraceContextPropagator::getInstance())
    ->setAutoShutdown(true)
    ->buildAndRegisterGlobal();

$root = $tracerProvider->getTracer('react-http-demo')->spanBuilder('root')->startSpan();
$rootScope = $root->activate();

try {
    $browser = new Browser();

    $requests = [
        new Request('GET', 'https://postman-echo.com/get'),
        new Request('POST', 'https://postman-echo.com/post'),
        new Request('GET', 'https://httpbin.org/does-not-exist'),
        new Request('GET', 'https://httpbin.org/get'),
        new Request('PUT', 'localhost:2222/not-found'),
    ];

    foreach ($requests as $request) {
        try {
            $response = await($browser->request($request->getMethod(), $request->getUri()));
            echo sprintf('[%d] ', $response->getStatusCode()) . json_decode($response->getBody()->getContents())->url . PHP_EOL;
        } catch (Throwable $t) {
            var_dump($t->getMessage());
        }
    }
} finally {
    $rootScope->detach();
    $root->end();
}
