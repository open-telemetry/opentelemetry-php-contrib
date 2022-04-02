<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\Util;

use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;

class NodeBuilderHelper
{
    public static function append(NodeBuilder $nodeBuilder, NodeDefinition ...$nodeDefinition): NodeBuilder
    {
        foreach ($nodeDefinition as $node) {
            $nodeBuilder->append($node);
        }

        return $nodeBuilder;
    }

    public static function appendAndEnd(NodeBuilder $nodeBuilder, NodeDefinition ...$nodeDefinition): NodeDefinition
    {
        return self::append($nodeBuilder, ...$nodeDefinition)->end();
    }
}
