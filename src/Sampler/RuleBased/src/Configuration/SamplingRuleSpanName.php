<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Contrib\Sampler\Configuration;

use Nevay\OTelSDK\Configuration\ComponentProvider;
use Nevay\OTelSDK\Configuration\ComponentProviderRegistry;
use Nevay\OTelSDK\Configuration\Context;
use Nevay\OTelSDK\Configuration\Validation;
use Nevay\OTelSDK\Contrib\Sampler\SamplingRule;
use Nevay\OTelSDK\Contrib\Sampler\SamplingRule\SpanNameRule;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

final class SamplingRuleSpanName implements ComponentProvider {

    /**
     * @param array{
     *     pattern: string,
     * } $properties
     */
    public function createPlugin(array $properties, Context $context): SamplingRule {
        return new SpanNameRule(
            $properties['pattern'],
        );
    }

    public function getConfig(ComponentProviderRegistry $registry): ArrayNodeDefinition {
        $node = new ArrayNodeDefinition('span_name');
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
