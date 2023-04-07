<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection;

use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\Symfony\OtelSdkBundle\Util\ExporterDsnParser;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * @codeCoverageIgnore
 */

/** @phan-file-suppress PhanUndeclaredClassReference */
class Configuration implements ConfigurationInterface
{
    // PUBLIC CONSTANTS
    public const ROOT_KEY = 'otel_sdk';
    public const CUSTOM_TYPE = 'custom';
    public const DEFAULT_TYPE = 'default';
    public const CLASS_NODE = 'class';
    public const ID_NODE = 'id';
    public const RESOURCE_NODE = 'resource';
    public const LIMITS_NODE = 'limits';
    public const LIMITS_COUNT_DEFAULT = 128;
    public const ATTRIBUTES_NODE = 'attributes';
    public const SERVICE_NAME_ATTR = 'service.name';
    public const SERVICE_NAME_NODE = 'service_name';
    public const REQUIRED_SOURCE_ATTRS = [
        self::SERVICE_NAME_ATTR,
    ];
    public const TRACE_NODE = 'trace';
    public const SAMPLER_NODE = 'sampler';
    public const ROOT_NODE = 'root';
    public const REMOTE_NODE = 'remote';
    public const LOCAL_NODE = 'local';
    public const SAMPLED_NODE = 'sampled';
    public const NOT_SAMPLED_NODE = 'not_sampled';
    public const ALWAYS_ON_SAMPLER = 'always_on';
    public const ALWAYS_OFF_SAMPLER = 'always_off';
    public const TRACE_ID_RATIO_SAMPLER = 'trace_id_ratio_based';
    public const PARENT_BASED_SAMPLER = 'parent_based';
    public const SAMPLER_NODE_DEFAULT = self::ALWAYS_ON_SAMPLER;
    public const SAMPLER_NODE_VALUES = [
        self::ALWAYS_ON_SAMPLER,
        self::ALWAYS_OFF_SAMPLER,
        self::TRACE_ID_RATIO_SAMPLER,
    ];
    public const PROBABILITY = 'probability';
    public const PROBABILITY_DEFAULT = 1.0;
    public const SPAN_NODE = 'span';
    public const ATTR_COUNT_NODE = 'attribute_count';
    public const ATTR_VALUE_LENGTH_NODE = 'attribute_value_length';
    public const EVENT_COUNT_NODE = 'event_count';
    public const LINK_COUNT_NODE = 'link_count';
    public const ATTRS_EVENT_NODE = 'attribute_per_event';
    public const ATTRS_LINK_NODE = 'attribute_per_link';
    public const SPAN_LIMIT_ATTRS = [
        self::ATTR_COUNT_NODE,
        self::ATTR_VALUE_LENGTH_NODE,
        self::EVENT_COUNT_NODE,
        self::LINK_COUNT_NODE,
        self::ATTRS_EVENT_NODE,
        self::ATTRS_LINK_NODE,
    ];
    public const PROCESSORS_NODE = 'processors';
    public const SIMPLE_PROCESSOR = 'simple';
    public const BATCH_PROCESSOR = 'batch';
    public const NOOP_PROCESSOR = 'noop';
    public const MULTI_PROCESSOR = 'multi';
    public const PROCESSOR_DEFAULT = self::BATCH_PROCESSOR;
    public const PROCESSOR_NODE_VALUES = [
        self::SIMPLE_PROCESSOR,
        self::BATCH_PROCESSOR,
        self::NOOP_PROCESSOR,
    ];
    public const EXPORTERS_NODE = 'exporters';
    public const PROCESSOR_NODE = 'processor';
    public const DSN_NODE = 'dsn';
    public const TYPE_NODE = 'type';
    public const URL_NODE = 'url';
    public const OPTIONS_NODE = 'options';
    public const NAME_KEY = 'name';
    public const ENV_TYPE = 'env';
    public const JAEGER_EXPORTER = 'jaeger';
    public const ZIPKIN_EXPORTER = 'zipkin';
    public const NEWRELIC_EXPORTER = 'newrelic';
    public const OTLP_HTTP_EXPORTER = 'otlphttp';
    public const OTLP_GRPC_EXPORTER = 'otlpgrpc';
    public const ZIPKIN_TO_NEWRELIC_EXPORTER = 'zipkintonewrelic';
    public const EXPORTERS_NODE_VALUES = [
        self::JAEGER_EXPORTER,
        self::ZIPKIN_EXPORTER,
        self::NEWRELIC_EXPORTER,
        self::OTLP_HTTP_EXPORTER,
        self::OTLP_GRPC_EXPORTER,
        self::ZIPKIN_TO_NEWRELIC_EXPORTER,
    ];

    // PRIVATE CONSTANTS
    private const SCALAR_NODE_TYPE = 'scalar';
    private const RESOURCE_XML = 'resource';
    private const PROCESSORS_XML = 'processor';
    private const EXPORTERS_XML = 'exporter';
    private const OPTIONS_XML = 'option';
    private const ENV_PREFIX = 'env_';
    private const EXPORTER_HR = 'span exporter';
    private const PROCESSOR_HR = 'span processor';

    // PRIVATE PROPERTIES
    private ?LoggerInterface $logger = null;
    private bool $debug;

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
        $this->debug('Start building config tree in ' . __CLASS__);
        $treeBuilder = new TreeBuilder(self::ROOT_KEY);
        $rootNode = $treeBuilder->getRootNode()
            ->canBeDisabled()
        ;
        self::addResourceSection($rootNode);
        self::addTraceSection($rootNode);

        $this->debug('Finished building config tree in ' . __CLASS__);

        return $treeBuilder;
    }

    private static function addResourceSection(ArrayNodeDefinition $node): void
    {
        $node
            ->children()
            ->arrayNode(self::RESOURCE_NODE)
            ->children()
                ->append(self::createResourceLimitsNode())
                ->append(self::createResourceAttributesNode())
            ->end();
    }

    private static function addTraceSection(ArrayNodeDefinition $node): void
    {
        $node
            ->children()
            ->arrayNode(self::TRACE_NODE)
            ->canBeDisabled()
            ->children()
                ->append(self::createSamplerSectionNode())
                ->append(self::createSpanSectionNode())
                ->append(self::createExporters())
            ->end();
    }

    private static function createResourceLimitsNode(): NodeDefinition
    {
        return (new TreeBuilder(self::LIMITS_NODE))
            ->getRootNode()
            ->children()
                ->integerNode(self::ATTR_COUNT_NODE)
                    ->defaultValue(self::LIMITS_COUNT_DEFAULT)
                ->end()
                ->integerNode(self::ATTR_VALUE_LENGTH_NODE)
                    ->defaultValue(PHP_INT_MAX)
                ->end()
            ->end()
        ;
    }

    private static function createResourceAttributesNode(): NodeDefinition
    {
        return (new TreeBuilder(self::ATTRIBUTES_NODE))
            ->getRootNode()
            ->beforeNormalization()
            ->ifTrue(static function ($v) {
                foreach (self::REQUIRED_SOURCE_ATTRS as $attr) {
                    if (!isset($v[$attr])) {
                        return true;
                    }
                }

                return false;
            })
            ->then(static function ($v) {
                throw new ConfigurationException(
                    sprintf(
                        'Opentelemetry configuration must provide following resource attributes:  %s ',
                        implode(', ', self::REQUIRED_SOURCE_ATTRS)
                    )
                );
            })
            ->end()
            ->fixXmlConfig(self::RESOURCE_XML)
            ->useAttributeAsKey(self::NAME_KEY)
            ->prototype(self::SCALAR_NODE_TYPE)
            ->end()
        ;
    }

    private static function createSamplerSectionNode(): NodeDefinition
    {
        return (new TreeBuilder(self::SAMPLER_NODE))
            ->getRootNode()
            ->beforeNormalization()
            ->ifString()
            ->then(static function ($v) {
                return [self::ROOT_NODE => $v];
            })
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
        return (new TreeBuilder($name))
            ->getRootNode()
            ->beforeNormalization()
                ->ifString()
                ->then(static function ($v) {
                    return [self::TYPE_NODE => $v];
                })
            ->end()
            ->beforeNormalization()
                ->ifTrue(static function ($v) {
                    return $v[self::TYPE_NODE] === self::CUSTOM_TYPE;
                })
                ->then(static function ($v) {
                    self::validateCustomService($v);

                    return $v;
                })
            ->end()
            ->beforeNormalization()
                ->ifTrue(static function ($v) {
                    return $v[self::TYPE_NODE] !== self::CUSTOM_TYPE;
                })
                ->then(static function ($v) {
                    if (!in_array($v[self::TYPE_NODE], self::SAMPLER_NODE_VALUES)) {
                        throw new ConfigurationException(
                            sprintf(
                                'sampler type must be either "custom" or one of : %s. Given: %s',
                                implode(', ', self::SAMPLER_NODE_VALUES),
                                $v[self::TYPE_NODE]
                            )
                        );
                    }

                    return $v;
                })
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
        return (new TreeBuilder(self::SPAN_NODE))
            ->getRootNode()
            ->children()
                ->append(self::createSpanLimitsNode())
                ->append(self::createSpanProcessors())
            ->end()
        ;
    }

    private static function createSpanLimitsNode(): NodeDefinition
    {
        return (new TreeBuilder(self::LIMITS_NODE))
            ->getRootNode()
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
        return (new TreeBuilder(self::PROCESSORS_NODE))
            ->getRootNode()
                ->beforeNormalization()
                ->ifString()
                ->castToArray()
                ->end()
                ->fixXmlConfig(self::PROCESSORS_XML)
                ->arrayPrototype()
                ->beforeNormalization()
                    ->ifString()
                    ->then(static function ($v) {
                        return [self::TYPE_NODE => $v];
                    })
                    ->end()
                    ->beforeNormalization()
                    ->ifTrue(static function ($v) {
                        return $v[self::TYPE_NODE] === self::CUSTOM_TYPE;
                    })
                    ->then(static function ($v) {
                        self::validateCustomService($v, self::PROCESSOR_HR);

                        return $v;
                    })
                    ->end()
                    ->beforeNormalization()
                    ->ifTrue(static function ($v) {
                        return $v[self::TYPE_NODE] !== self::CUSTOM_TYPE;
                    })
                    ->then(static function ($v) {
                        if (!in_array($v[self::TYPE_NODE], self::PROCESSOR_NODE_VALUES)) {
                            throw new ConfigurationException(
                                sprintf(
                                    'span processor type must be either "custom" or one of : %s. Given: %s',
                                    implode(', ', self::PROCESSOR_NODE_VALUES),
                                    $v[self::TYPE_NODE]
                                )
                            );
                        }

                        return $v;
                    })
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
        return (new TreeBuilder(self::EXPORTERS_NODE))
            ->getRootNode()
            ->requiresAtLeastOneElement()
            ->beforeNormalization()
                ->ifString()
                ->castToArray()
            ->end()
            ->fixXmlConfig(self::EXPORTERS_XML)
            ->arrayPrototype()
            ->beforeNormalization()
                ->always()
                ->then(static function ($v) {
                    return self::normalizeExporterConfig($v);
                })
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
        return (new TreeBuilder(self::OPTIONS_NODE))
            ->getRootNode()
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

    /**
     * @param mixed $config
     * @return array|string[]
     */
    private static function normalizeExporterConfig($config): array
    {
        if (is_array($config)) {
            // exporter is set via - dsn: type+scheme://host:123/path
            if (isset($config[self::DSN_NODE])) {
                $dsn = $config[self::DSN_NODE];
                if (is_string($dsn)) {
                    return self::normalizeExporterConfig($dsn);
                }

                throw new ConfigurationException(
                    'Exporter configuration "dsn" value must be a string'
                );
            }
            if (isset($config[self::TYPE_NODE])) {
                self::validateTypedExporterConfig($config);
                
                return $config;
            }

            throw new ConfigurationException(
                'Exporter configuration must either have a key "dsn", keys "type"+"url" or type=custom and keys "class" or "id"'
            );
        }
        if (is_string($config)) {
            if (self::isEnvVarReference($config)) {
                return [
                    self::TYPE_NODE => self::ENV_TYPE,
                    self::URL_NODE => $config,
                ];
            }

            return self::exporterDsnToArray($config);
        }

        throw new ConfigurationException(
            'Exporter configuration must be either a dsn or an array'
        );
    }

    private static function exporterDsnToArray(string $config): array
    {
        return ExporterDsnParser::parse($config)->asConfigArray();
    }

    private static function isEnvVarReference(string $value): bool
    {
        return stripos($value, self::ENV_PREFIX) === 0;
    }

    private static function validateTypedExporterConfig(array $config)
    {
        // custom exporter
        if ($config[self::TYPE_NODE] === self::CUSTOM_TYPE) {
            self::validateCustomExporterConfig($config);
        }
        // exporter is set via eg.  - [type: jaeger, url: scheme://host:123/path]
        if (isset($config[self::URL_NODE])) {
            //return $config;
        }
    }

    private static function validateCustomExporterConfig(array $config)
    {
        // custom exporters need class or id provided.
        self::validateCustomService(
            $config,
            self::EXPORTER_HR
        );

        if (isset($config[self::CLASS_NODE])) {
            // custom exporters classes need to be a valid FQCN
            self::validateCustomClass(
                $config[self::CLASS_NODE],
                self::EXPORTER_HR
            );
            // custom span exporters need to implement OpenTelemetry\SDK\Trace\SpanExporterInterface
            self::validateCustomClassImplements(
                $config[self::CLASS_NODE],
                SpanExporterInterface::class,
                self::EXPORTER_HR
            );
        }
    }

    private static function validateCustomService(array $config, string $type = '')
    {
        if (!isset($config[self::CLASS_NODE]) && !isset($config[self::ID_NODE])) {
            throw new ConfigurationException(
                sprintf(
                    'Custom %s service needs a "class" or "id" option to be configured',
                    $type
                )
            );
        }
        if (isset($config[self::CLASS_NODE]) && isset($config[self::ID_NODE])) {
            throw new ConfigurationException(
                sprintf(
                    'Custom %s service needs either a "class" or "id" option to be configured, not both',
                    $type
                )
            );
        }
        if (isset($config[self::CLASS_NODE])) {
            self::validateCustomClass($config[self::CLASS_NODE]);
        }
    }

    private static function validateCustomClass(string $fqcn, string $type = '')
    {
        if (!class_exists($fqcn)) {
            throw new ConfigurationException(
                sprintf(
                    'Could not find configured custom %s class. given: %s',
                    $type,
                    $fqcn
                )
            );
        }
    }

    private static function validateCustomClassImplements(string $fqcn, string $interface, string $type = '')
    {
        if (!self::classImplemets($fqcn, $interface)) {
            throw new ConfigurationException(
                sprintf(
                    'Custom %s class need to implement %s',
                    $type,
                    SpanExporterInterface::class
                )
            );
        }
    }

    private static function classImplemets(string $fqcn, string $interface): bool
    {
        try {
            return in_array($interface, (new ReflectionClass($fqcn))->getInterfaceNames());
        } catch (\Throwable $t) {
            return false;
        }
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    private function debug(string $message, array $context = [])
    {
        if (!$this->isDebug() || !$this->logger instanceof LoggerInterface) {
            return;
        }

        $this->logger->debug($message, $context);
    }

    private function isDebug(): bool
    {
        return $this->debug;
    }
}
