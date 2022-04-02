<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection;

use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\ConfigurationBehavior;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\ResourceConfiguration;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\TraceConfiguration;
use OpenTelemetry\Symfony\OtelSdkBundle\Util\TreeBuilderHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class Configuration implements ConfigurationInterface
{
    use ConfigurationBehavior;

    /**
     * @param bool $debug The kernel.debug value
     */
    public function __construct(bool $debug, ?LoggerInterface $logger = null)
    {
        $this->debug = $debug;
        $this->logger = $logger;
    }

    public static function getName(): string
    {
        return ConfigurationInterface::ROOT_KEY;
    }

    public static function canBeDisabled(): bool
    {
        return true;
    }

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $this->startDebug();
        $treeBuilder = TreeBuilderHelper::createArrayTreeBuilderAndAppend(
            self::getName(),
            self::canBeDisabled(),
            self::createResourceNode($this->isDebug(), $this->getLogger()),
            self::createTracesNode($this->isDebug(), $this->getLogger())
        );

        $this->endDebug();

        return $treeBuilder;
    }

    private static function createResourceNode(bool $debug, ?LoggerInterface $logger = null)
    {
        return (new ResourceConfiguration($debug, $logger))->getConfigTreeBuilder()->getRootNode();
    }

    private static function createTracesNode(bool $debug, ?LoggerInterface $logger = null)
    {
        return (new TraceConfiguration($debug, $logger))->getConfigTreeBuilder()->getRootNode();
    }
}
