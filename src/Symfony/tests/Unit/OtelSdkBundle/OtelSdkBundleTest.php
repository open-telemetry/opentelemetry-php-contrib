<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Symfony\Unit\OtelSdkBundle;

use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\OtelSdkExtension;
use OpenTelemetry\Symfony\OtelSdkBundle\OtelSdkBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class OtelSdkBundleTest extends TestCase
{
    public function testBuild()
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

    public function testGetContainerExtension()
    {
        $this->assertInstanceOf(
            OtelSdkExtension::class,
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
