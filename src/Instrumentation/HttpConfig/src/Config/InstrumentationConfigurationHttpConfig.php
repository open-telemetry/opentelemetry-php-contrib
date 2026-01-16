<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\HttpConfig\Config;

use function array_map;
use OpenTelemetry\API\Configuration\Config\ComponentPlugin;
use OpenTelemetry\API\Configuration\Config\ComponentProvider;
use OpenTelemetry\API\Configuration\Config\ComponentProviderRegistry;
use OpenTelemetry\API\Configuration\Context;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\InstrumentationConfiguration;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\HttpClientConfig;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\HttpConfig;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\HttpServerConfig;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer\DefaultSanitizer;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer\MultiSanitizer;
use Override;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;

/**
 * @implements ComponentProvider<InstrumentationConfiguration>
 */
final class InstrumentationConfigurationHttpConfig implements ComponentProvider
{

    /**
     * @param array{
     *     client?: array{
     *         capture_url_scheme?: bool,
     *         capture_url_template?: bool,
     *         capture_user_agent_original?: bool,
     *         capture_user_agent_synthetic_type?: bool,
     *         capture_network_transport?: bool,
     *         capture_request_body_size?: bool,
     *         capture_request_size?: bool,
     *         capture_response_body_size?: bool,
     *         capture_response_size?: bool,
     *     },
     *     server: array{
     *         capture_client_port?: bool,
     *         capture_user_agent_synthetic_type?: bool,
     *         capture_network_transport?: bool,
     *         capture_network_local_address?: bool,
     *         capture_network_local_port?: bool,
     *         capture_request_body_size?: bool,
     *         capture_request_size?: bool,
     *         capture_response_body_size?: bool,
     *         capture_response_size?: bool,
     *     },
     *     uri_sanitizers: ?list<ComponentPlugin<UriSanitizer>>,
     *     known_http_methods: list<string>,
     * } $properties
     */
    #[Override]
    public function createPlugin(array $properties, Context $context): InstrumentationConfiguration
    {
        return new HttpConfig(
            client: new HttpClientConfig(
                captureUrlScheme: $properties['client']['capture_url_scheme'] ?? false,
                captureUrlTemplate: $properties['client']['capture_url_template'] ?? false,
                captureUserAgentOriginal: $properties['client']['capture_user_agent_original'] ?? false,
                captureUserAgentSyntheticType: $properties['client']['capture_user_agent_synthetic_type'] ?? false,
                captureNetworkTransport: $properties['client']['capture_network_transport'] ?? false,
                captureRequestBodySize: $properties['client']['capture_request_body_size'] ?? false,
                captureRequestSize: $properties['client']['capture_request_size'] ?? false,
                captureResponseBodySize: $properties['client']['capture_response_body_size'] ?? false,
                captureResponseSize: $properties['client']['capture_response_size'] ?? false,
            ),
            server: new HttpServerConfig(
                captureClientPort: $properties['server']['capture_client_port'] ?? false,
                captureUserAgentSyntheticType: $properties['server']['capture_user_agent_synthetic_type'] ?? false,
                captureNetworkTransport: $properties['server']['capture_network_transport'] ?? false,
                captureNetworkLocalAddress: $properties['server']['capture_network_local_address'] ?? false,
                captureNetworkLocalPort: $properties['server']['capture_network_local_port'] ?? false,
                captureRequestBodySize: $properties['server']['capture_request_body_size'] ?? false,
                captureRequestSize: $properties['server']['capture_request_size'] ?? false,
                captureResponseBodySize: $properties['server']['capture_response_body_size'] ?? false,
                captureResponseSize: $properties['server']['capture_response_size'] ?? false,
            ),
            sanitizer: $properties['uri_sanitizers'] === null
                ? new DefaultSanitizer()
                : MultiSanitizer::composite(array_map(static fn (ComponentPlugin $sanitizer) => $sanitizer->create($context), $properties['uri_sanitizers'])),
            knownHttpMethods: $properties['known_http_methods'],
        );
    }

    #[Override]
    public function getConfig(ComponentProviderRegistry $registry, NodeBuilder $builder): ArrayNodeDefinition
    {
        $node = $builder->arrayNode('http');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('client')
                    ->children()
                        ->booleanNode('capture_url_scheme')->treatNullLike(true)->end()
                        ->booleanNode('capture_url_template')->treatNullLike(true)->end()
                        ->booleanNode('capture_user_agent_original')->treatNullLike(true)->end()
                        ->booleanNode('capture_user_agent_synthetic_type')->treatNullLike(true)->end()
                        ->booleanNode('capture_network_transport')->treatNullLike(true)->end()
                        ->booleanNode('capture_request_body_size')->treatNullLike(true)->end()
                        ->booleanNode('capture_request_size')->treatNullLike(true)->end()
                        ->booleanNode('capture_response_body_size')->treatNullLike(true)->end()
                        ->booleanNode('capture_response_size')->treatNullLike(true)->end()
                    ->end()
                ->end()
                ->arrayNode('server')
                    ->children()
                        ->booleanNode('capture_client_port')->treatNullLike(true)->end()
                        ->booleanNode('capture_user_agent_synthetic_type')->treatNullLike(true)->end()
                        ->booleanNode('capture_network_transport')->treatNullLike(true)->end()
                        ->booleanNode('capture_network_local_address')->treatNullLike(true)->end()
                        ->booleanNode('capture_network_local_port')->treatNullLike(true)->end()
                        ->booleanNode('capture_request_body_size')->treatNullLike(true)->end()
                        ->booleanNode('capture_request_size')->treatNullLike(true)->end()
                        ->booleanNode('capture_response_body_size')->treatNullLike(true)->end()
                        ->booleanNode('capture_response_size')->treatNullLike(true)->end()
                    ->end()
                ->end()
                ->append($registry->componentList('uri_sanitizers', UriSanitizer::class)->defaultNull())
                ->arrayNode('known_http_methods')
                    ->defaultValue(HttpConfig::HTTP_METHODS)
                    ->scalarPrototype()->end()
                ->end()
            ->end()
        ;

        return $node;
    }
}
