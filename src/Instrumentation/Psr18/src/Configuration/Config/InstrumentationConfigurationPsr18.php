<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Psr18\Configuration\Config;

use OpenTelemetry\API\Configuration\Config\ComponentProvider;
use OpenTelemetry\API\Configuration\Config\ComponentProviderRegistry;
use OpenTelemetry\API\Configuration\Context;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\InstrumentationConfiguration;
use OpenTelemetry\Contrib\Instrumentation\Psr18\Configuration\Psr18Configuration;
use Override;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;

/**
 * @implements ComponentProvider<InstrumentationConfiguration>
 */
final class InstrumentationConfigurationPsr18 implements ComponentProvider
{
    /**
     * @param array{
     *     enabled?: bool,
     * } $properties
     */
    #[Override]
    public function createPlugin(array $properties, Context $context): InstrumentationConfiguration
    {
        return new Psr18Configuration(
            enabled: $properties['enabled'] ?? false,
        );
    }

    #[Override]
    public function getConfig(ComponentProviderRegistry $registry, NodeBuilder $builder): ArrayNodeDefinition
    {
        return $builder
            ->arrayNode('psr18')
            ->canBeDisabled();
    }
}
