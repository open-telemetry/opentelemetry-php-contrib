<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Symfony\OtelBundle\HttpKernel\RequestListener;

return function (ContainerConfigurator $configurator): void {
    $configurator->services()->set(RequestListener::class)
        ->autoconfigure()
        ->arg('$tracerProvider', service(TracerProviderInterface::class))
        ->arg('$propagator', service(TextMapPropagatorInterface::class))
    ;

    $configurator->services()->set(NoopTextMapPropagator::class);
    $configurator->services()->set(NoopTracerProvider::class);
    $configurator->services()->set(NoopMeterProvider::class);
};
