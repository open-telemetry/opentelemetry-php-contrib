<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Sampler\RuleBased\ComponentProvider;

use OpenTelemetry\Config\SDK\Configuration\ComponentProvider;
use OpenTelemetry\Config\SDK\Configuration\ComponentProviderRegistry;
use OpenTelemetry\Config\SDK\Configuration\Context;
use OpenTelemetry\Config\SDK\Configuration\Validation;
use OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule;
use OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule\AttributeRule;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

/**
 * @implements ComponentProvider<SamplingRule>
 */
final class SamplingRuleAttribute implements ComponentProvider
{

    /**
     * @param array{
     *     key: string,
     *     pattern: string,
     * } $properties
     * @psalm-suppress ArgumentTypeCoercion
     */
    public function createPlugin(array $properties, Context $context): SamplingRule
    {
        return new AttributeRule(
            $properties['key'],
            $properties['pattern'],
        );
    }

    public function getConfig(ComponentProviderRegistry $registry): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('attribute');

        /** @psalm-suppress PossiblyNullReference */
        $node
            ->children()
                ->scalarNode('key')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->validate()->always(Validation::ensureString())->end()
                ->end()
                ->scalarNode('pattern')
                    ->isRequired()
                    ->validate()->always(Validation::ensureRegexPattern())->end()
                ->end()
            ->end()
        ;

        return $node;
    }
}
