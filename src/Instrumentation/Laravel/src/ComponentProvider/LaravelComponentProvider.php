<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\ComponentProvider;

use OpenTelemetry\API\Instrumentation\AutoInstrumentation\InstrumentationConfiguration;
use OpenTelemetry\Config\SDK\Configuration\ComponentProvider;
use OpenTelemetry\Config\SDK\Configuration\ComponentProviderRegistry;
use OpenTelemetry\Config\SDK\Configuration\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelConfiguration;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

class LaravelComponentProvider implements ComponentProvider
{
    public function createPlugin(array $properties, Context $context): InstrumentationConfiguration
    {
        return LaravelConfiguration::fromArray($properties);
    }

    public function getConfig(ComponentProviderRegistry $registry): ArrayNodeDefinition
    {
        $root = new ArrayNodeDefinition('laravel');

        return $root
            ->canBeDisabled()
            ->addDefaultsIfNotSet()
        ;
    }
}
