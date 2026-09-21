<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Configuration\Config;

use OpenTelemetry\API\Configuration\Config\ComponentProvider;
use OpenTelemetry\API\Configuration\Config\ComponentProviderRegistry;
use OpenTelemetry\API\Configuration\Context;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\InstrumentationConfiguration;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelConfiguration;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;

/**
 * @implements ComponentProvider<LaravelConfiguration>
 */
class LaravelComponentProvider implements ComponentProvider
{
    /**
     * @param array{
     *     enabled: bool,
     *     trace_cli_enabled: bool,
     * } $properties
     * @psalm-suppress LessSpecificImplementedReturnType,MoreSpecificImplementedParamType
     */
    #[\Override]
    public function createPlugin(array $properties, Context $context): InstrumentationConfiguration
    {
        return new LaravelConfiguration(
            enabled: $properties['enabled'],
            traceCliEnabled: $properties['trace_cli_enabled'],
        );
    }

    /**
     * @psalm-suppress PossiblyNullReference,UndefinedInterfaceMethod
     */
    #[\Override]
    public function getConfig(ComponentProviderRegistry $registry, NodeBuilder $builder): ArrayNodeDefinition
    {
        return $builder
            ->arrayNode('laravel')
            ->canBeDisabled()
            ->children()
                ->booleanNode('trace_cli_enabled')->defaultFalse()->end()
            ->end() // @phpstan-ignore method.notFound
        ;
    }
}
