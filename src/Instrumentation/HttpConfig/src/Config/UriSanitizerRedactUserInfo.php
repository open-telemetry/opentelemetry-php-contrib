<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\HttpConfig\Config;

use OpenTelemetry\API\Configuration\Config\ComponentProvider;
use OpenTelemetry\API\Configuration\Config\ComponentProviderRegistry;
use OpenTelemetry\API\Configuration\Context;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer\RedactUsernamePasswordSanitizer;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;

/**
 * @implements ComponentProvider<UriSanitizer>
 */
final class UriSanitizerRedactUserInfo implements ComponentProvider
{

    /**
     * @param array{
     * } $properties
     */
    public function createPlugin(array $properties, Context $context): UriSanitizer
    {
        return new RedactUsernamePasswordSanitizer();
    }

    public function getConfig(ComponentProviderRegistry $registry, NodeBuilder $builder): ArrayNodeDefinition
    {
        return $builder->arrayNode('redact_userinfo');
    }
}
