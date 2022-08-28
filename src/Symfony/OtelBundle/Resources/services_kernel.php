<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Symfony\OtelBundle\HttpKernel\RequestListener;

return function (ContainerConfigurator $configurator): void {
    $configurator->services()->set(RequestListener::class)
        ->autoconfigure()
        ->arg('$tracerProvider', service(TracerProviderInterface::class))
        ->arg('$propagator', service(TextMapPropagatorInterface::class))
        ->arg('$requestHeaders', param('otel.tracing.http.server.request_headers'))
        ->arg('$responseHeaders', param('otel.tracing.http.server.response_headers'))
    ;
};
