<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Contrib\Sampler\Configuration;

use Nevay\OTelSDK\Configuration\ComponentPlugin;
use Nevay\OTelSDK\Configuration\ComponentProvider;
use Nevay\OTelSDK\Configuration\ComponentProviderRegistry;
use Nevay\OTelSDK\Configuration\Context;
use Nevay\OTelSDK\Contrib\Sampler\AlwaysRecordingSampler;
use Nevay\OTelSDK\Trace\Sampler;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

final class SamplerAlwaysRecording implements ComponentProvider {

    /**
     * @param array{
     *     sampler: ComponentPlugin<Sampler>
     * } $properties
     */
    public function createPlugin(array $properties, Context $context): Sampler {
        return new AlwaysRecordingSampler(
            $properties['sampler']->create($context),
        );
    }

    public function getConfig(ComponentProviderRegistry $registry): ArrayNodeDefinition {
        $node = new ArrayNodeDefinition('always_recording');
        $node
            ->children()
                ->append($registry->component('sampler', Sampler::class)->isRequired())
            ->end()
        ;

        return $node;
    }
}
