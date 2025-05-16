<?php

declare(strict_types=1);

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Common\Export\Stream\StreamTransportFactory;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use function React\Async\async;
use function React\Async\await;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Http\Message\Request;
use React\Http\Message\ResponseException;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$transport = (new StreamTransportFactory())->create('php://output', 'application/json');
$exporter = new ConsoleSpanExporter($transport);

$tracerProvider = new TracerProvider(
    new SimpleSpanProcessor($exporter),
    new AlwaysOnSampler(),
    ResourceInfoFactory::emptyResource(),
);

Sdk::builder()
    ->setTracerProvider($tracerProvider)
    ->setPropagator(TraceContextPropagator::getInstance())
    ->setAutoShutdown(true)
    ->buildAndRegisterGlobal();

$context = Context::getCurrent();

$root = $tracerProvider->getTracer('reactphp-demo')->spanBuilder('root')->startSpan();

$timer = Loop::addPeriodicTimer(1, function () {
    echo 'Some other event loop event' . PHP_EOL;
});

Loop::futureTick(async(static function () use ($context, $root, $timer) {
    $contextScope = $context->activate();
    $rootScope = $root->activate();

    try {
        sleep(1);

        $browser = new Browser();

        $requests = [
            new Request('GET', 'https://postman-echo.com/get'),
            new Request('GET', 'https://postman-echo.com/stream/33554432'),
            new Request('POST', 'https://postman-echo.com/post', ['Content-Type' => 'application/json'], '{}'),
            new Request('CUSTOM', 'http://postman-echo.com:443/get'),
            new Request('GET', 'unknown://postman-echo.com/get'),
            new Request('GET', 'https://postman-echo.com/delay/2'),
        ];

        foreach ($requests as $request) {
            try {
                $response = await($browser->request($request->getMethod(), $request->getUri()));
                echo sprintf(
                    '[HTTP/%s %d %s] %s%s',
                    $response->getProtocolVersion(),
                    $response->getStatusCode(),
                    $response->getReasonPhrase(),
                    $request->getUri(),
                    PHP_EOL
                );
            } catch (ResponseException $e) {
                $response = $e->getResponse();
                echo sprintf(
                    '[HTTP/%s %d %s] %s%s',
                    $response->getProtocolVersion(),
                    $response->getStatusCode(),
                    $response->getReasonPhrase(),
                    $request->getUri(),
                    PHP_EOL
                );
            } catch (Throwable $t) {
                echo sprintf(
                    '[%d: %s] %s%s',
                    $t->getCode(),
                    $t->getMessage(),
                    $request->getUri(),
                    PHP_EOL
                );
            }
        }
    } finally {
        $rootScope->detach();
        $root->end();
        $contextScope->detach();
        Loop::cancelTimer($timer);
    }
}));
