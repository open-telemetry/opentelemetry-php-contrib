<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Sampler\RuleBased\ComponentProvider;

use OpenTelemetry\Config\SDK\Configuration\ComponentPlugin;
use OpenTelemetry\Config\SDK\Configuration\ComponentProvider;
use OpenTelemetry\Config\SDK\Configuration\ComponentProviderRegistry;
use OpenTelemetry\Config\SDK\Configuration\Context;
use OpenTelemetry\Contrib\Sampler\RuleBased\RuleBasedSampler;
use OpenTelemetry\Contrib\Sampler\RuleBased\RuleSet;
use OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

/**
 * @implements ComponentProvider<SamplerInterface>
 */
final class SamplerRuleBased implements ComponentProvider
{
    /**
     * @param array{
     *     rule_sets: list<array{
     *         rules: list<ComponentPlugin<SamplingRule>>,
     *         delegate: ComponentPlugin<SamplerInterface>,
     *     }>,
     *     fallback: ComponentPlugin<SamplerInterface>,
     * } $properties
     */
    public function createPlugin(array $properties, Context $context): SamplerInterface
    {
        $ruleSets = [];
        foreach ($properties['rule_sets'] as $ruleSet) {
            $samplingRules = [];
            foreach ($ruleSet['rules'] as $rule) {
                $samplingRules[] = $rule->create($context);
            }

            $ruleSets[] = new RuleSet(
                $samplingRules,
                $ruleSet['delegate']->create($context),
            );
        }

        return new RuleBasedSampler(
            $ruleSets,
            $properties['fallback']->create($context),
        );
    }

    public function getConfig(ComponentProviderRegistry $registry): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('contrib_rule_based');

        /** @psalm-suppress PossiblyNullReference */
        $node
            ->children()
                ->arrayNode('rule_sets')
                    ->arrayPrototype()
                        ->children()
                            ->append($registry->componentArrayList('rules', SamplingRule::class)->isRequired()->cannotBeEmpty())
                            ->append($registry->component('delegate', SamplerInterface::class)->isRequired())
                        ->end()
                    ->end()
                ->end()
                ->append($registry->component('fallback', SamplerInterface::class)->isRequired())
            ->end()
        ;

        return $node;
    }
}
