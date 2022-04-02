<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\DependencyInjection;

use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration
 */
class ConfigurationTest extends TestCase
{
    public function test_get_config_tree_builder(): void
    {
        $rootNode = (new Configuration(false))
            ->getConfigTreeBuilder()
            ->getRootNode();

        $this->assertInstanceOf(ArrayNodeDefinition::class, $rootNode);

        $children = $rootNode->getChildNodeDefinitions();

        $this->assertArrayHasKey(ConfigurationInterface::RESOURCE_NODE, $children);
        $this->assertArrayHasKey(ConfigurationInterface::TRACE_NODE, $children);
    }
}
