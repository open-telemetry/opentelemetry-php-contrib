<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Symfony\Unit\OtelSdkBundle\Util;

use OpenTelemetry\Contrib\Symfony\OtelSdkBundle\Util\ContainerConfiguratorHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServiceConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

class ContainerConfiguratorHelperTest extends TestCase
{
    public function testCreate()
    {
        $this->assertInstanceOf(
            ContainerConfiguratorHelper::class,
            ContainerConfiguratorHelper::create(
                $this->createContainerConfiguratorMock()
            )
        );
    }

    public function testGetServicesConfigurator()
    {
        $this->assertInstanceOf(
            ServicesConfigurator::class,
            ContainerConfiguratorHelper::create(
                $this->createContainerConfiguratorMock()
            )->getServicesConfigurator()
        );
    }

    public function testSetService()
    {
        $this->assertInstanceOf(
            ServiceConfigurator::class,
            ContainerConfiguratorHelper::create(
                $this->createContainerConfiguratorMock()
            )->setService(__CLASS__, false)
        );
    }

    public function testSetServiceWithAlias()
    {
        $this->assertInstanceOf(
            ServiceConfigurator::class,
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

        $mock->expects($this->any())
            ->method('services')
            ->willReturnCallback(function () {
                return $this->createMock(ServicesConfigurator::class);
            });

        return $mock;
    }
}
