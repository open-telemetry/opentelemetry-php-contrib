<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\Util;

use InvalidArgumentException;
    use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class TreeBuilderHelper
{
    public static function createArrayTreeBuilder(string $name, bool $canBeDisabled = false): TreeBuilder
    {
        $treeBuilder = new TreeBuilder($name);

        if ($canBeDisabled) {
            $treeBuilder->getRootNode()
                ->canBeDisabled()
            ;
        }

        return $treeBuilder;
    }

    public static function createArrayTreeBuilderAndAppend(
        string $name,
        bool $canBeDisabled = false,
        NodeDefinition ...$nodeDefinition
    ): TreeBuilder {
        $treeBuilder =  self::createArrayTreeBuilder($name, $canBeDisabled);

        NodeBuilderHelper::appendAndEnd(
            self::getArrayRootNodeChildren(
                $treeBuilder
            ),
            ...$nodeDefinition
        );

        return $treeBuilder;
    }

    public static function createArrayRootNode(string $name, bool $canBeDisabled = false): ArrayNodeDefinition
    {
        return self::getArrayRootNode(
            self::createArrayTreeBuilder($name, $canBeDisabled)
        );
    }

    public static function createArrayRootNodeChildren(string $name, bool $canBeDisabled = false): NodeBuilder
    {
        return self::getArrayRootNodeChildren(
            self::createArrayTreeBuilder($name, $canBeDisabled)
        );
    }

    public static function addDisabledNode(TreeBuilder $treeBuilder): TreeBuilder
    {
        self::getArrayRootNode($treeBuilder)
            ->canBeDisabled()
        ;

        return $treeBuilder;
    }

    public static function getArrayRootNode(TreeBuilder $treeBuilder): ArrayNodeDefinition
    {
        $rootNode = $treeBuilder->getRootNode();

        if (!$rootNode instanceof ArrayNodeDefinition) {
            throw new InvalidArgumentException(
                sprintf(
                    'Root node of "%s" is not a "%s"',
                    get_class($treeBuilder),
                    ArrayNodeDefinition::class
                )
            );
        }

        return $rootNode;
    }

    public static function getArrayRootNodeChildren(TreeBuilder $treeBuilder): NodeBuilder
    {
        return self::getArrayRootNode($treeBuilder)->children();
    }
}
