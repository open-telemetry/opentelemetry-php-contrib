<?php

declare(strict_types=1);

use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\ConsoleMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Sdk;
use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Http\Message\Request;
use React\Http\Message\ResponseException;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$exporter = new ConsoleMetricExporter(Temporality::CUMULATIVE);

$meterProvider = MeterProvider::builder()
    ->addReader(new ExportingReader($exporter))
    ->setResource(ResourceInfoFactory::emptyResource())
    ->build();

Sdk::builder()
    ->setMeterProvider($meterProvider)
    ->setAutoShutdown(true)
    ->buildAndRegisterGlobal();

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
            ->request($request->getMethod(), $request->getUri())
            ->then(function (ResponseInterface $response) use ($request) {
                echo sprintf(
                    '[HTTP/%s %d %s] %s%s',
                    $response->getProtocolVersion(),
                    $response->getStatusCode(),
                    $response->getReasonPhrase(),
                    $request->getUri(),
                    PHP_EOL
                );
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
    $meterProvider->forceFlush();
}
