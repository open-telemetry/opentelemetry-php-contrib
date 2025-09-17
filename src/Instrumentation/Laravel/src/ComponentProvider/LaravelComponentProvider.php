<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\ComponentProvider;

use OpenTelemetry\API\Configuration\Config\ComponentProvider;
use OpenTelemetry\API\Configuration\Config\ComponentProviderRegistry;
use OpenTelemetry\API\Configuration\Context;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\InstrumentationConfiguration;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelConfiguration;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;

class LaravelComponentProvider implements ComponentProvider
{
    /**
     * @phan-suppress PhanTypeMismatchReturn
     */
    public function createPlugin(array $properties, Context $context): InstrumentationConfiguration
    {
        return LaravelConfiguration::fromArray($properties);
    }

    public function getConfig(ComponentProviderRegistry $registry, NodeBuilder $builder): ArrayNodeDefinition
    {
        return $builder
            ->arrayNode('laravel')
            ->canBeDisabled()
            ->addDefaultsIfNotSet()
        ;
    }
}
