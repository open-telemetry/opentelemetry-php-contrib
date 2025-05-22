<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Sampler\RuleBased\ComponentProvider;

use OpenTelemetry\Config\SDK\Configuration\ComponentProvider;
use OpenTelemetry\Config\SDK\Configuration\ComponentProviderRegistry;
use OpenTelemetry\Config\SDK\Configuration\Context;
use OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule;
use OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule\ParentRule;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;

/**
 * @implements ComponentProvider<SamplingRule>
 */
final class SamplingRuleParent implements ComponentProvider
{

    /**
     * @param array{
     *     sampled: bool,
     *     remote: ?bool,
     * } $properties
     */
    public function createPlugin(array $properties, Context $context): SamplingRule
    {
        return new ParentRule(
            $properties['sampled'],
            $properties['remote'],
        );
    }

    public function getConfig(ComponentProviderRegistry $registry, NodeBuilder $builder): ArrayNodeDefinition
    {
        $node = $builder->arrayNode('parent');

        /** @psalm-suppress PossiblyNullReference */
        $node
            ->children()
                ->booleanNode('sampled')->isRequired()->end()
                ->booleanNode('remote')->defaultNull()->end()
            ->end()
        ;

        return $node;
    }
}
