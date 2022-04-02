<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration;

use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure\MissingAttributesCallback;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationInterface;
use OpenTelemetry\Symfony\OtelSdkBundle\Util\NodeDefinitionHelper;
use OpenTelemetry\Symfony\OtelSdkBundle\Util\TreeBuilderHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class ResourceConfiguration implements ConfigurationInterface
{
    use ConfigurationBehavior;

    // PRIVATE CONSTANTS
    private const SCALAR_NODE_TYPE = 'scalar';
    private const RESOURCE_XML = 'resource';

    /**
     * @param bool $debug The kernel.debug value
     */
    public function __construct(bool $debug = false, ?LoggerInterface $logger = null)
    {
        $this->debug = $debug;
        $this->logger = $logger;
    }

    public function getConfigTreeBuilder(): TreeBuilder
    {
        return TreeBuilderHelper::createArrayTreeBuilderAndAppend(
            self::getName(),
            self::canBeDisabled(),
            self::createResourceLimitsNode(),
            self::createResourceAttributesNode()
        );
    }

    public static function getName(): string
    {
        return ConfigurationInterface::RESOURCE_NODE;
    }

    public static function canBeDisabled(): bool
    {
        return false;
    }

    private static function createResourceLimitsNode(): NodeDefinition
    {
        return NodeDefinitionHelper::endNodeBuilder(
            NodeDefinitionHelper::endNodeBuilder(
                TreeBuilderHelper::createArrayRootNodeChildren(self::LIMITS_NODE)
                        ->integerNode(self::ATTR_COUNT_NODE)
                        ->defaultValue(self::LIMITS_COUNT_DEFAULT)
            )
                ->integerNode(self::ATTR_VALUE_LENGTH_NODE)
                ->defaultValue(PHP_INT_MAX)
        )->end();
    }

    private static function createResourceAttributesNode(): NodeDefinition
    {
        return TreeBuilderHelper::createArrayRootNode(ConfigurationInterface::ATTRIBUTES_NODE)
            ->beforeNormalization()
            ->ifTrue(
                MissingAttributesCallback::createCheck(
                    ConfigurationInterface::REQUIRED_SOURCE_ATTRS
                )
            )
            ->then(
                MissingAttributesCallback::createExceptionTrigger(
                    ConfigurationInterface::REQUIRED_SOURCE_ATTRS
                )
            )
            ->end()
            ->fixXmlConfig(self::RESOURCE_XML)
            ->useAttributeAsKey(self::NAME_KEY)
            ->prototype(self::SCALAR_NODE_TYPE)
            ->end()
            ;
    }
}
