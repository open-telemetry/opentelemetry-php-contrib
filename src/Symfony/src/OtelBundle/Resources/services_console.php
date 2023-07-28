<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Contrib\Symfony\OtelBundle\Console\ConsoleListener;

return static function (ContainerConfigurator $configurator): void {
    $configurator->services()->set(ConsoleListener::class)
        ->autoconfigure()
        ->arg('$tracerProvider', service(TracerProviderInterface::class))
    ;
};
