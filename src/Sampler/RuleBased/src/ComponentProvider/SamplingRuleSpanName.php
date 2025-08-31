<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Sampler\RuleBased\ComponentProvider;

use OpenTelemetry\Config\SDK\Configuration\ComponentProvider;
use OpenTelemetry\Config\SDK\Configuration\ComponentProviderRegistry;
use OpenTelemetry\Config\SDK\Configuration\Context;
use OpenTelemetry\Config\SDK\Configuration\Validation;
use OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule;
use OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule\SpanNameRule;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;

/**
 * @implements ComponentProvider<SamplingRule>
 */
final class SamplingRuleSpanName implements ComponentProvider
{

    /**
     * @param array{
     *     pattern: string,
     * } $properties
     * @psalm-suppress ArgumentTypeCoercion
     */
    public function createPlugin(array $properties, Context $context): SamplingRule
    {
        return new SpanNameRule(
            $properties['pattern'],
        );
    }

    public function getConfig(ComponentProviderRegistry $registry, NodeBuilder $builder): ArrayNodeDefinition
    {
        $node = $builder->arrayNode('span_name');

        /** @psalm-suppress PossiblyNullReference */
        $node
            ->children()
                ->scalarNode('pattern')
                    ->isRequired()
                    ->validate()->always(Validation::ensureRegexPattern())->end()
                ->end()
            ->end()
        ;

        return $node;
    }
}
