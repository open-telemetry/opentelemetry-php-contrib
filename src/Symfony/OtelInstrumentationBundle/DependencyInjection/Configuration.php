<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelInstrumentationBundle\DependencyInjection;

use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * @codeCoverageIgnore
 */
class Configuration implements ConfigurationInterface
{
    // PUBLIC CONSTANTS
    public const ROOT_KEY = 'otel_instrumentation';
    public const CLASS_NODE = 'class';
    public const ID_NODE = 'id';
    public const INSTRUMENTATION_NODE = 'instrumentation';
    public const ATTRIBUTES_NODE = 'attributes';
    public const OPTIONS_NODE = 'options';
    public const NAME_KEY = 'name';

    // PRIVATE CONSTANTS
    private const SCALAR_NODE_TYPE = 'scalar';
    private const RESOURCE_XML = 'resource';

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
        self::addInstrumentationSection($rootNode);

        $this->debug('Finished building config tree in ' . __CLASS__);

        return $treeBuilder;
    }

    private static function addInstrumentationSection(ArrayNodeDefinition $node): void
    {
        $node
            ->children()
            ->arrayNode(self::INSTRUMENTATION_NODE)
            ->children()
                ->append(self::symfonyHttpClientNode())
            ->end();
    }

    private static function symfonyHttpClientNode(): NodeDefinition
    {
        return (new TreeBuilder(self::SYMFONY_HTTP_NODE))
            ->getRootNode()
            ->children()
                ->booleanNode(self::ATTR_ENABLED)
                    ->defaultValue(true)
                ->end()
            ->end()
            ;
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
