<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Contrib\Sampler\Configuration;

use Nevay\OTelSDK\Configuration\ComponentProvider;
use Nevay\OTelSDK\Configuration\ComponentProviderRegistry;
use Nevay\OTelSDK\Configuration\Context;
use Nevay\OTelSDK\Contrib\Sampler\SamplingRule;
use Nevay\OTelSDK\Contrib\Sampler\SamplingRule\SpanKindRule;
use Nevay\OTelSDK\Trace\Span\Kind;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

final class SamplingRuleSpanKind implements ComponentProvider {

    /**
     * @param array{
     *     kind: 'INTERNAL'|'CLIENT'|'SERVER'|'PRODUCER'|'CONSUMER',
     * } $properties
     */
    public function createPlugin(array $properties, Context $context): SamplingRule {
        return new SpanKindRule(
            match ($properties['kind']) {
                'INTERNAL' => Kind::Internal,
                'CLIENT' => Kind::Client,
                'SERVER' => Kind::Server,
                'PRODUCER' => Kind::Producer,
                'CONSUMER' => Kind::Consumer,
            },
        );
    }

    public function getConfig(ComponentProviderRegistry $registry): ArrayNodeDefinition {
        $node = new ArrayNodeDefinition('span_kind');
        $node
            ->children()
                ->enumNode('kind')
                    ->isRequired()
                    ->values([
                        'INTERNAL',
                        'CLIENT',
                        'SERVER',
                        'PRODUCER',
                        'CONSUMER',
                    ])
                ->end()
            ->end()
        ;

        return $node;
    }
}
