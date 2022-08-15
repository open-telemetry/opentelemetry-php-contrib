<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\Context\Propagation\NoopTextMapPropagator;

return function (ContainerConfigurator $configurator): void {
    $configurator->services()->set(NoopTextMapPropagator::class);
    $configurator->services()->set(NoopTracerProvider::class);
    $configurator->services()->set(NoopMeterProvider::class);
};
