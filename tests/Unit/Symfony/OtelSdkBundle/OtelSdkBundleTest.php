<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle;

use OpenTelemetry\Symfony\OtelSdkBundle\OtelSdkBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\OtelSdkBundle
 */
class OtelSdkBundleTest extends TestCase
{
    public function testBuild(): void
    {
        $bundle = new OtelSdkBundle();

        $bundle->build(
            $this->getBuilderMock()
        );

        $this->assertInstanceOf(
            OtelSdkBundle::class,
            $bundle
        );
    }

    public function testGetContainerExtension(): void
    {
        $this->assertEquals(
            (new OtelSdkBundle())->getContainerExtension(),
            (new OtelSdkBundle())->getContainerExtension()
        );
    }

    /**
     * @return ContainerBuilder
     */
    private function getBuilderMock(): ContainerBuilder
    {
        return $this->getMockBuilder(ContainerBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
    }
}
