<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\Symfony\OpenTelemetryBundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class OpenTelemetryBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
    }
}
