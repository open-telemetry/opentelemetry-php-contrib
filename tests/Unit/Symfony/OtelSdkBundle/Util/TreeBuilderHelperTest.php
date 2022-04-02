<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\Util;

use Generator;
use InvalidArgumentException;
use OpenTelemetry\Symfony\OtelSdkBundle\Util\TreeBuilderHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\Util\TreeBuilderHelper
 */
class TreeBuilderHelperTest extends TestCase
{
    /**
     * @var MockObject|TreeBuilder
     */
    private TreeBuilder $treeBuilder;

    /**
     * @var MockObject|ArrayNodeDefinition|NodeDefinition
     */
    private NodeDefinition $rootNode;

    /**
     * @dataProvider canBeDisabledProvider
     */
    public function test_create_array_tree_builder(bool $canBeDisabled): void
    {
        $this->assertInstanceOf(
            ArrayNodeDefinition::class,
            TreeBuilderHelper::createArrayTreeBuilder('foo', $canBeDisabled)->getRootNode()
        );
    }

    /**
     * @dataProvider canBeDisabledProvider
     */
    public function test_create_array_tree_builder_and_append(bool $canBeDisabled): void
    {
        $this->assertInstanceOf(
            ArrayNodeDefinition::class,
            TreeBuilderHelper::createArrayTreeBuilderAndAppend(
                'foo',
                $canBeDisabled,
                $this->createMock(NodeDefinition::class),
                $this->createMock(NodeDefinition::class)
            )->getRootNode()
        );
    }

    public function canBeDisabledProvider(): Generator
    {
        yield [true];
        yield [false];
    }

    public function test_create_array_root_node(): void
    {
        $name = 'foo';

        $this->assertEquals(
            (new TreeBuilder($name))->getRootNode(),
            TreeBuilderHelper::createArrayRootNode($name)
        );
    }

    public function test_create_array_root_node_children(): void
    {
        $name = 'foo';

        $this->assertEquals(
            (new TreeBuilder($name))->getRootNode()->children(),
            TreeBuilderHelper::createArrayRootNodeChildren($name)
        );
    }

    public function test_add_disabled_node(): void
    {
        $this->getRootNode()
            ->expects($this->once())
            ->method('canBeDisabled');

        TreeBuilderHelper::addDisabledNode($this->getTreeBuilder());
    }

    public function test_get_array_root_node(): void
    {
        $this->assertSame(
            $this->getTreeBuilder()->getRootNode(),
            TreeBuilderHelper::getArrayRootNode($this->getTreeBuilder())
        );
    }

    public function test_get_array_root_node_throws_exception(): void
    {
        $this->setRootNode(
            $this->createMock(NodeDefinition::class)
        );

        $this->expectException(InvalidArgumentException::class);

        TreeBuilderHelper::getArrayRootNode($this->getTreeBuilder());
    }

    public function test_get_array_root_node_children(): void
    {
        $nodeBuilder = $this->createMock(NodeBuilder::class);
        $this->getRootNode()
            ->expects($this->once())
            ->method('children')
            ->willReturn($nodeBuilder);

        $this->assertSame(
            $nodeBuilder,
            TreeBuilderHelper::getArrayRootNodeChildren($this->getTreeBuilder())
        );
    }

    /**
     * @return MockObject|TreeBuilder
     */
    private function getTreeBuilder()
    {
        return $this->treeBuilder ?? $this->treeBuilder = $this->createTreeBuilderMock();
    }

    /**
     * @return MockObject|ArrayNodeDefinition|NodeDefinition
     */
    private function getRootNode()
    {
        return $this->rootNode ?? $this->rootNode = $this->createArrayNodeDefinitionMock();
    }

    private function setRootNode(NodeDefinition $rootNode): void
    {
        $this->rootNode = $rootNode;
    }

    /**
     * @return MockObject|TreeBuilder
     */
    private function createTreeBuilderMock()
    {
        $treeBuilder = $this->createMock(TreeBuilder::class);

        $treeBuilder
            ->method('getRootNode')
            ->willReturn(
                $this->getRootNode()
            );

        return $treeBuilder;
    }

    /**
     * @return MockObject|ArrayNodeDefinition
     */
    private function createArrayNodeDefinitionMock()
    {
        return $this->createMock(ArrayNodeDefinition::class);
    }
}
