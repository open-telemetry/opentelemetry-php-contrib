<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\Util;

use InvalidArgumentException;
use OpenTelemetry\Symfony\OtelSdkBundle\Util\NodeDefinitionHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\Util\NodeDefinitionHelper
 */
class NodeDefinitionHelperTest extends TestCase
{
    public function test_end_node_definition(): void
    {
        $node = $this->createMock(NodeDefinition::class);
        $result = $this->createMock(NodeDefinition::class);
        $node->method('end')
            ->willReturn($result);

        $this->assertSame(
            $result,
            NodeDefinitionHelper::endNodeDefinition($node)
        );
    }

    public function test_end_node_definition_throws_exception(): void
    {
        $node = $this->createMock(NodeDefinition::class);
        $node->method('end')
            ->willReturn(null);

        $this->expectException(InvalidArgumentException::class);

        NodeDefinitionHelper::endNodeDefinition($node);
    }

    public function test_end_node_builder(): void
    {
        $node = $this->createMock(NodeDefinition::class);
        $result = $this->createMock(NodeBuilder::class);
        $node->method('end')
            ->willReturn($result);

        $this->assertSame(
            $result,
            NodeDefinitionHelper::endNodeBuilder($node)
        );
    }

    public function test_end_node_builder_throws_exception(): void
    {
        $node = $this->createMock(NodeDefinition::class);
        $result = $this->createMock(NodeDefinition::class);
        $node->method('end')
            ->willReturn($result);

        $this->expectException(InvalidArgumentException::class);

        NodeDefinitionHelper::endNodeBuilder($node);
    }
}
