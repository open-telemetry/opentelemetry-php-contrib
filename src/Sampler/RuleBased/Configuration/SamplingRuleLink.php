<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Contrib\Sampler\Configuration;

use Nevay\OTelSDK\Configuration\ComponentProvider;
use Nevay\OTelSDK\Configuration\ComponentProviderRegistry;
use Nevay\OTelSDK\Configuration\Context;
use Nevay\OTelSDK\Contrib\Sampler\SamplingRule;
use Nevay\OTelSDK\Contrib\Sampler\SamplingRule\LinkRule;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

final class SamplingRuleLink implements ComponentProvider {

    /**
     * @param array{
     *     sampled: bool,
     *     remote: ?bool,
     * } $properties
     */
    public function createPlugin(array $properties, Context $context): SamplingRule {
        return new LinkRule(
            $properties['sampled'],
            $properties['remote'],
        );
    }

    public function getConfig(ComponentProviderRegistry $registry): ArrayNodeDefinition {
        $node = new ArrayNodeDefinition('link');
        $node
            ->children()
                ->booleanNode('sampled')->isRequired()->end()
                ->booleanNode('remote')->defaultNull()->end()
            ->end()
        ;

        return $node;
    }
}
