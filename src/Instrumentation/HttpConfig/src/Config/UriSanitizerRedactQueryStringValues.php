<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\HttpConfig\Config;

use OpenTelemetry\API\Configuration\Config\ComponentProvider;
use OpenTelemetry\API\Configuration\Config\ComponentProviderRegistry;
use OpenTelemetry\API\Configuration\Context;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer\RedactSensitiveQueryStringValuesSanitizer;
use Override;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;

/**
 * @implements ComponentProvider<UriSanitizer>
 */
final class UriSanitizerRedactQueryStringValues implements ComponentProvider
{

    /**
     * @param array{
     *     query_keys: list<string>,
     * } $properties
     */
    #[Override]
    public function createPlugin(array $properties, Context $context): UriSanitizer
    {
        return new RedactSensitiveQueryStringValuesSanitizer($properties['query_keys']);
    }

    #[Override]
    public function getConfig(ComponentProviderRegistry $registry, NodeBuilder $builder): ArrayNodeDefinition
    {
        $node = $builder->arrayNode('redact_query_string_values');
        $node
            ->children()
                ->arrayNode('query_keys')
                    ->isRequired()
                    ->scalarPrototype()->end()
                ->end()
            ->end()
        ;

        return $node;
    }
}
