<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelBundle\DependencyInjection;

use function class_exists;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\HttpKernel\HttpKernel;

/**
 * @psalm-suppress PossiblyNullReference,PossiblyUndefinedMethod
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $builder = new TreeBuilder('otel');

        $tracing = $builder->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('tracing')
                ->addDefaultsIfNotSet();

        if (class_exists(HttpKernel::class)) {
            $tracing->children()->append($this->kernelTracingNode());
        }

        return $builder;
    }

    private function kernelTracingNode(): NodeDefinition
    {
        ($kernel = new ArrayNodeDefinition('kernel'))
            ->addDefaultsIfNotSet()
            ->canBeDisabled()
            ->fixXmlConfig('requestHeader')
            ->fixXmlConfig('responseHeader')
            ->children()
                ->booleanNode('extractRemoteContext')->defaultTrue()->end()
                ->arrayNode('requestHeaders')
                    ->beforeNormalization()->castToArray()->end()
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
                ->arrayNode('responseHeaders')
                    ->beforeNormalization()->castToArray()->end()
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
            ->end()
        ;

        return $kernel;
    }
}
