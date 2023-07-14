<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Symfony\OtelBundle\DependencyInjection;

use function class_exists;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Console\Application;
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

        $tracing->children()->append($this->httpTracingNode());

        if (class_exists(Application::class)) {
            $tracing->children()->append($this->consoleTracingNode());
        }
        if (class_exists(HttpKernel::class)) {
            $tracing->children()->append($this->kernelTracingNode());
        }

        return $builder;
    }

    private function httpTracingNode(): NodeDefinition
    {
        return (new ArrayNodeDefinition('http'))
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('server')
                    ->addDefaultsIfNotSet()
                    ->fixXmlConfig('requestHeader')
                    ->fixXmlConfig('responseHeader')
                    ->children()
                        ->arrayNode('requestHeaders')
                            ->info('Request headers to capture as span attributes.')
                            ->example(['Content-Type', 'X-Forwarded-For'])
                            ->beforeNormalization()->castToArray()->end()
                            ->scalarPrototype()->cannotBeEmpty()->end()
                        ->end()
                        ->arrayNode('responseHeaders')
                            ->info('Response headers to capture as span attributes.')
                            ->example(['Content-Type'])
                            ->beforeNormalization()->castToArray()->end()
                            ->scalarPrototype()->cannotBeEmpty()->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    private function consoleTracingNode(): NodeDefinition
    {
        return (new ArrayNodeDefinition('console'))
            ->canBeDisabled()
        ;
    }

    private function kernelTracingNode(): NodeDefinition
    {
        return (new ArrayNodeDefinition('kernel'))
            ->addDefaultsIfNotSet()
            ->canBeDisabled()
            ->children()
                ->booleanNode('extractRemoteContext')
                    ->info('Set to `false` if the kernel runs in a runtime that extracts the remote context before passing the request to the kernel.')
                    ->defaultTrue()
                ->end()
            ->end()
        ;
    }
}
