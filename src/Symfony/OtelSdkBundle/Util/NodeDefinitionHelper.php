<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\Util;

use InvalidArgumentException;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;

class NodeDefinitionHelper
{
    public static function endNodeDefinition(NodeDefinition $nodeDefinition): NodeDefinition
    {
        $result = $nodeDefinition->end();

        if (!$result instanceof NodeDefinition) {
            throw new InvalidArgumentException(
                sprintf(
                    'Call to "%s"::end() did not return a "%s"',
                    get_class($nodeDefinition),
                    NodeDefinition::class
                )
            );
        }

        return $result;
    }

    public static function endNodeBuilder(NodeDefinition $nodeDefinition): NodeBuilder
    {
        $result = $nodeDefinition->end();

        if (!$result instanceof NodeBuilder) {
            throw new InvalidArgumentException(
                sprintf(
                    'Call to "%s"::end() did not return a "%s"',
                    get_class($nodeDefinition),
                    NodeBuilder::class
                )
            );
        }

        return $result;
    }
}
