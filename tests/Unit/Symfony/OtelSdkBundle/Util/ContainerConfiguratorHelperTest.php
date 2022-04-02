<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\Util;

use DG\BypassFinals;
use OpenTelemetry\Symfony\OtelSdkBundle\Util\ContainerConfiguratorHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\Util\ContainerConfiguratorHelper
 */
class ContainerConfiguratorHelperTest extends TestCase
{
    public function setUp(): void
    {
        BypassFinals::enable();
    }

    public function testCreate(): void
    {
        $this->assertEquals(
            ContainerConfiguratorHelper::create(
                $this->createContainerConfiguratorMock()
            ),
            ContainerConfiguratorHelper::create(
                $this->createContainerConfiguratorMock()
            )
        );
    }

    public function testGetServicesConfigurator(): void
    {
        $this->assertEquals(
            ContainerConfiguratorHelper::create(
                $this->createContainerConfiguratorMock()
            )->getServicesConfigurator(),
            ContainerConfiguratorHelper::create(
                $this->createContainerConfiguratorMock()
            )->getServicesConfigurator()
        );
    }

    public function testSetService(): void
    {
        $this->assertEquals(
            ContainerConfiguratorHelper::create(
                $this->createContainerConfiguratorMock()
            )->setService(__CLASS__, false),
            ContainerConfiguratorHelper::create(
                $this->createContainerConfiguratorMock()
            )->setService(__CLASS__, false)
        );
    }

    public function testSetServiceWithAlias(): void
    {
        $this->assertEquals(
            ContainerConfiguratorHelper::create(
                $this->createContainerConfiguratorMock()
            )->setService(__CLASS__, true),
            ContainerConfiguratorHelper::create(
                $this->createContainerConfiguratorMock()
            )->setService(__CLASS__, true)
        );
    }

    /**
     * @return ContainerConfigurator
     */
    private function createContainerConfiguratorMock(): ContainerConfigurator
    {
        $mock =  $this->getMockBuilder(ContainerConfigurator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mock
            ->method('services')
            ->willReturnCallback(function () {
                return $this->createMock(ServicesConfigurator::class);
            });

        return $mock;
    }
}
