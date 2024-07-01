<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Contrib\Sampler\Configuration;

use Nevay\OTelSDK\Configuration\ComponentPlugin;
use Nevay\OTelSDK\Configuration\ComponentProvider;
use Nevay\OTelSDK\Configuration\ComponentProviderRegistry;
use Nevay\OTelSDK\Configuration\Context;
use Nevay\OTelSDK\Contrib\Sampler\RuleBasedSampler;
use Nevay\OTelSDK\Contrib\Sampler\RuleSet;
use Nevay\OTelSDK\Contrib\Sampler\SamplingRule;
use Nevay\OTelSDK\Trace\Sampler;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

final class SamplerRuleBased implements ComponentProvider {

    /**
     * @param array{
     *     rule_sets: list<array{
     *         rules: list<ComponentPlugin<SamplingRule>>,
     *         delegate: ComponentPlugin<Sampler>,
     *     }>,
     *     fallback: ComponentPlugin<Sampler>,
     * } $properties
     */
    public function createPlugin(array $properties, Context $context): Sampler {
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

    public function getConfig(ComponentProviderRegistry $registry): ArrayNodeDefinition {
        $node = new ArrayNodeDefinition('rule_based');
        $node
            ->children()
                ->arrayNode('rule_sets')
                    ->arrayPrototype()
                        ->children()
                            ->append($registry->componentList('rules', SamplingRule::class)->isRequired()->cannotBeEmpty())
                            ->append($registry->component('delegate', Sampler::class)->isRequired())
                        ->end()
                    ->end()
                ->end()
                ->append($registry->component('fallback', Sampler::class)->isRequired())
            ->end()
        ;

        return $node;
    }
}
