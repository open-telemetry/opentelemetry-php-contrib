<?php

declare(strict_types=1);

// $ PHP_VERSION=8.1 docker-compose run --rm php -dextension=examples/instrumentation/Psr18/otel_instrumentation.so examples/instrumentation/Psr18/request.php

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use function OpenTelemetry\Instrumentation\Psr18\enableHttpClientTracing;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 3) . '/src/Instrumentation/Psr18/client_tracing.php';

$tracerProvider =  new TracerProvider(
    new BatchSpanProcessor(new ConsoleSpanExporter(), ClockFactory::getDefault()),
    new AlwaysOnSampler(),
    ResourceInfoFactory::emptyResource(),
);
enableHttpClientTracing(
    $tracerProvider->getTracer('io.opentelemetry.contrib.php'),
    TraceContextPropagator::getInstance(),
);

$client = new Client();

$response = $client->sendRequest(new Request('GET', 'https://postman-echo.com/get'));
echo json_encode(json_decode($response->getBody()->getContents()), JSON_PRETTY_PRINT), PHP_EOL;

$tracerProvider->shutdown();
