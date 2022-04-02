<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\Util;

use OpenTelemetry\Symfony\OtelSdkBundle\Util\NodeBuilderHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\Util\NodeBuilderHelper
 */
class NodeBuilderHelperTest extends TestCase
{
    public function test_append(): void
    {
        $nodes = [];
        $nodeNr = 4;
        for ($x = 0; $x < $nodeNr; $x++) {
            $nodes[] = $this->createMock(NodeDefinition::class);
        }

        $nodeBuilder = $this->createMock(NodeBuilder::class);
        $nodeBuilder->expects(
            $this->exactly(count($nodes))
        )->method('append');

        NodeBuilderHelper::append($nodeBuilder, ...$nodes);
    }

    public function test_append_and_end(): void
    {
        $nodes = [];
        $nodeNr = 4;
        for ($x = 0; $x < $nodeNr; $x++) {
            $nodes[] = $this->createMock(NodeDefinition::class);
        }

        $nodeBuilder = $this->createMock(NodeBuilder::class);
        $nodeBuilder->expects($this->once())
            ->method('end')
            ->willReturn(
                $this->createMock(NodeDefinition::class)
            );

        NodeBuilderHelper::appendAndEnd($nodeBuilder, ...$nodes);
    }
}
