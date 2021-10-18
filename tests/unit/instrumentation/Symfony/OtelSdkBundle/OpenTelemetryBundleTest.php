<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle;

use OpenTelemetry\Instrumentation\Symfony\OtelSdkBundle\DependencyInjection\OtelSdkExtension;
use OpenTelemetry\Instrumentation\Symfony\OtelSdkBundle\OtelSdkBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class OpenTelemetryBundleTest extends TestCase
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