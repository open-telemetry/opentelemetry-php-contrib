<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration;

use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure\ConfigurationExceptionCallback;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure\CustomServiceValidatorCallback;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure\ExporterConfigNormalizerCallback;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure\IsNodeTypeCallback;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure\IsOneOfNodeTypesCallback;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure\LogicalEndCallback;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\Closure\StringToArrayCallback;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationInterface;
use OpenTelemetry\Symfony\OtelSdkBundle\Util\TreeBuilderHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class TraceConfiguration implements ConfigurationInterface
{
    use ConfigurationBehavior;

    // PRIVATE CONSTANTS
    private const SCALAR_NODE_TYPE = 'scalar';
    private const PROCESSORS_XML = 'processor';
    private const EXPORTERS_XML = 'exporter';
    private const OPTIONS_XML = 'option';
    private const ENV_PREFIX = 'env_';
    private const EXPORTER_HR = 'span exporter';
    private const PROCESSOR_HR = 'span processor';

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
            self::createSamplerSectionNode(),
            self::createSpanSectionNode(),
            self::createExporters()
        );
    }

    public static function getName(): string
    {
        return ConfigurationInterface::TRACE_NODE;
    }

    public static function canBeDisabled(): bool
    {
        return true;
    }

    private static function createSamplerSectionNode(): NodeDefinition
    {
        return TreeBuilderHelper::createArrayRootNode(self::SAMPLER_NODE)
            ->beforeNormalization()
            ->ifString()
            ->then(
                StringToArrayCallback::create(self::ROOT_NODE)
            )
            ->end()
            ->append(self::createSamplerNode(self::ROOT_NODE))
            ->children()
            ->arrayNode(self::REMOTE_NODE)
            ->append(self::createSamplerNode(self::SAMPLED_NODE))
            ->append(self::createSamplerNode(self::NOT_SAMPLED_NODE, self::ALWAYS_OFF_SAMPLER))
            ->end()
            ->arrayNode(self::LOCAL_NODE)
            ->append(self::createSamplerNode(self::SAMPLED_NODE))
            ->append(self::createSamplerNode(self::NOT_SAMPLED_NODE, self::ALWAYS_OFF_SAMPLER))
            ->end()
            ->end()
            ;
    }

    private static function createSamplerNode(string $name, string $default = self::SAMPLER_NODE_DEFAULT): NodeDefinition
    {
        return TreeBuilderHelper::createArrayRootNode($name)
            ->beforeNormalization()
            ->ifString()
            ->then(
                StringToArrayCallback::create(self::TYPE_NODE)
            )
            ->end()
            ->beforeNormalization()
            ->ifTrue(
                IsNodeTypeCallback::create(self::CUSTOM_TYPE)
            )
            ->then(
                CustomServiceValidatorCallback::create()
            )
            ->end()
            ->beforeNormalization()
            ->ifTrue(
                LogicalEndCallback::create(
                    IsNodeTypeCallback::create(self::CUSTOM_TYPE, false),
                    IsOneOfNodeTypesCallback::create(self::SAMPLER_NODE_VALUES, false)
                )
            )
            ->then(
                ConfigurationExceptionCallback::create(
                    sprintf(
                        'sampler type must be either "custom" or one of : "%s". ',
                        implode('", "', self::SAMPLER_NODE_VALUES)
                    )
                )
            )
            ->end()
            ->children()
            ->append(self::createTypeNode($default))
            ->append(self::createClassNode())
            ->append(self::createIdNode())
            ->append(self::createOptionsNodes())
            ->end()
            ;
    }

    private static function createSpanSectionNode(): NodeDefinition
    {
        return TreeBuilderHelper::createArrayTreeBuilderAndAppend(
            self::SPAN_NODE,
            false,
            self::createSpanLimitsNode(),
            self::createSpanProcessors()
        )->getRootNode();
    }

    private static function createSpanLimitsNode(): NodeDefinition
    {
        return TreeBuilderHelper::createArrayRootNode(self::LIMITS_NODE)
            ->children()
            ->integerNode(self::ATTR_COUNT_NODE)->defaultValue(self::LIMITS_COUNT_DEFAULT)->end()
            ->integerNode(self::ATTR_VALUE_LENGTH_NODE)->defaultValue(PHP_INT_MAX)->end()
            ->integerNode(self::EVENT_COUNT_NODE)->defaultValue(self::LIMITS_COUNT_DEFAULT)->end()
            ->integerNode(self::LINK_COUNT_NODE)->defaultValue(self::LIMITS_COUNT_DEFAULT)->end()
            ->integerNode(self::ATTRS_EVENT_NODE)->defaultValue(self::LIMITS_COUNT_DEFAULT)->end()
            ->integerNode(self::ATTRS_LINK_NODE)->defaultValue(self::LIMITS_COUNT_DEFAULT)->end()
            ->end()
            ;
    }

    private static function createSpanProcessors(): NodeDefinition
    {
        return TreeBuilderHelper::createArrayRootNode(self::PROCESSORS_NODE)
            ->beforeNormalization()
            ->ifString()
            ->castToArray()
            ->end()
            ->fixXmlConfig(self::PROCESSORS_XML)
            ->arrayPrototype()
            ->beforeNormalization()
            ->ifString()
            ->then(
                StringToArrayCallback::create(self::TYPE_NODE)
            )
            ->end()
            ->beforeNormalization()
            ->ifTrue(
                IsNodeTypeCallback::create(self::CUSTOM_TYPE)
            )
            ->then(
                CustomServiceValidatorCallback::create()
            )
            ->end()
            ->beforeNormalization()
            ->ifTrue(
                LogicalEndCallback::create(
                    IsNodeTypeCallback::create(self::CUSTOM_TYPE, false),
                    IsOneOfNodeTypesCallback::create(self::PROCESSOR_NODE_VALUES, false)
                )
            )
            ->then(
                ConfigurationExceptionCallback::create(
                    sprintf(
                        'span processor type must be either "custom" or one of : %s.s',
                        implode(', ', self::PROCESSOR_NODE_VALUES)
                    )
                )
            )
            ->end()
            ->children()
            ->append(self::createTypeNode(self::PROCESSOR_DEFAULT))
            ->append(self::createClassNode())
            ->append(self::createIdNode())
            ->append(self::createOptionsNodes())
            ->end()
            ->end()
            ;
    }

    private static function createExporters(): NodeDefinition
    {
        return TreeBuilderHelper::createArrayRootNode(self::EXPORTERS_NODE)
            ->requiresAtLeastOneElement()
            ->beforeNormalization()
            ->ifString()
            ->castToArray()
            ->end()
            ->fixXmlConfig(self::EXPORTERS_XML)
            ->arrayPrototype()
            ->beforeNormalization()
            ->always()
            ->then(
                ExporterConfigNormalizerCallback::create()
            )
            ->end()
            ->children()
            ->append(self::createTypeNode())
            ->scalarNode(self::PROCESSOR_NODE)
            ->defaultValue(self::DEFAULT_TYPE)
            ->end()
            ->scalarNode(self::URL_NODE)->end()

            ->append(self::createClassNode())
            ->append(self::createIdNode())
            ->append(self::createOptionsNodes())

            ->end()
            ->end()
            ;
    }

    private static function createClassNode(): NodeDefinition
    {
        return (new TreeBuilder(self::CLASS_NODE, self::SCALAR_NODE_TYPE))
            ->getRootNode();
    }

    private static function createIdNode(): NodeDefinition
    {
        return (new TreeBuilder(self::ID_NODE, self::SCALAR_NODE_TYPE))
            ->getRootNode();
    }

    private static function createOptionsNodes(): NodeDefinition
    {
        return TreeBuilderHelper::createArrayRootNode(self::OPTIONS_NODE)
            ->fixXmlConfig(self::OPTIONS_XML)
            ->useAttributeAsKey(self::NAME_KEY)
            ->prototype(self::SCALAR_NODE_TYPE)->end();
    }

    private static function createTypeNode(?string $default = null): NodeDefinition
    {
        $node = (new TreeBuilder(self::TYPE_NODE, self::SCALAR_NODE_TYPE))
            ->getRootNode()
            ->isRequired();

        if ($default !== null) {
            $node->defaultValue($default);
        }

        return $node;
    }
}
