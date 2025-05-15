<?php

declare(strict_types=1);

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\SDK\Common\Export\Stream\StreamTransportFactory;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use React\Http\Browser;
use React\Http\Message\Request;
use React\Http\Message\ResponseException;
use React\Stream\ReadableStreamInterface;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$transport = (new StreamTransportFactory())->create('php://output', 'application/json');
$exporter = new ConsoleSpanExporter($transport);

$tracerProvider =  new TracerProvider(
    new SimpleSpanProcessor($exporter),
    new AlwaysOnSampler(),
    ResourceInfoFactory::emptyResource(),
);

Sdk::builder()
    ->setTracerProvider($tracerProvider)
    ->setPropagator(TraceContextPropagator::getInstance())
    ->setAutoShutdown(true)
    ->buildAndRegisterGlobal();

$root = $tracerProvider->getTracer('reactphp-demo')->spanBuilder('root')->startSpan();
$rootScope = $root->activate();

try {
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
        $browser
            ->requestStreaming($request->getMethod(), $request->getUri())
            ->then(function (ResponseInterface $response) use ($request) {
                $prefix = sprintf(
                    '[HTTP/%s %d %s] %s: ',
                    $response->getProtocolVersion(),
                    $response->getStatusCode(),
                    $response->getReasonPhrase(),
                    $request->getUri()
                );
                echo $prefix . 'headers received.' . PHP_EOL;

                $stream = $response->getBody();
                assert($stream instanceof StreamInterface);
                assert($stream instanceof ReadableStreamInterface);

                $stream->on('error', function (Throwable $t) use ($request) {
                    echo sprintf(
                        '[%d: %s] %s%s',
                        $t->getCode(),
                        $t->getMessage(),
                        $request->getUri(),
                        PHP_EOL
                    );
                });

                $stream->on('close', function () use ($prefix) {
                    echo $prefix . 'body received.' . PHP_EOL;
                });
            }, function (Throwable $t) use ($request) {
                if (is_a($t, ResponseException::class)) {
                    $response = $t->getResponse();
                    echo sprintf(
                        '[HTTP/%s %d %s] %s%s',
                        $response->getProtocolVersion(),
                        $response->getStatusCode(),
                        $response->getReasonPhrase(),
                        $request->getUri(),
                        PHP_EOL
                    );
                } else {
                    echo sprintf(
                        '[%d: %s] %s%s',
                        $t->getCode(),
                        $t->getMessage(),
                        $request->getUri(),
                        PHP_EOL
                    );
                }
            });
    }
} finally {
    $rootScope->detach();
    $root->end();
}
