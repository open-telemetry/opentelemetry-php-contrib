<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Shim\OpenTracing\Tracer;
use OpenTelemetry\SDK\Common\Util\ShutdownHandler;
use OpenTelemetry\SDK\Trace\TracerProviderFactory;
use OpenTracing as API;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Example of using opentracing-shim to use OpenTracing to export traces via OpenTelemetry.
 * Note that this example uses environment variables to configure a TracerProvider via a factory.
 */

putenv('OTEL_TRACES_EXPORTER=zipkin');
putenv('OTEL_EXPORTER_ZIPKIN_ENDPOINT=http://zipkin:9411/api/v2/spans');

$tracerProvider = (new TracerProviderFactory())->create();

$tracer = new Tracer($tracerProvider);
API\GlobalTracer::set($tracer);

//ensure pending traces are sent on shutdown
ShutdownHandler::register([$tracerProvider, 'shutdown']);

$span = $tracer->startSpan('zipkin-demo');
$span->finish();
