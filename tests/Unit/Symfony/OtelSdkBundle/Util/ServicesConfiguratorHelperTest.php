<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\Util;

use OpenTelemetry\Symfony\OtelSdkBundle\Util\ServicesConfiguratorHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServiceConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\Util\ServicesConfiguratorHelper
 */
class ServicesConfiguratorHelperTest extends TestCase
{
    /**
     * @var ServicesConfiguratorHelper
     */
    private ServicesConfiguratorHelper $helper;
    /**
     * @var ServicesConfigurator|MockObject
     */
    private ServicesConfigurator $configurator;

    public function setup(): void
    {
        // @phpstan-ignore-next-line
        $this->configurator = $this->createServicesConfiguratorMock();
        $this->helper = ServicesConfiguratorHelper::create(
            $this->configurator
        );
    }

    public function testCreate(): void
    {
        $this->assertInstanceOf(
            ServicesConfiguratorHelper::class,
            $this->helper
        );
    }

    public function testGetConfigurator(): void
    {
        $this->assertEquals(
            $this->helper->getConfigurator(),
            $this->helper->getConfigurator()
        );
    }

    public function testSetService(): void
    {
        $id = 'std_class';
        $class = stdClass::class;

        $this->configurator
            ->expects($this->exactly(2))
            ->method('set')
            ->with($id, $class)
            ->willReturn($this->createServiceConfiguratorMock());

        $this->assertSame(
            $this->helper->setService($class, false),
            $this->helper->setService($class, false)
        );
    }

    public function testSetServiceWithAlias(): void
    {
        $id = 'std_class';
        $class = stdClass::class;

        $this->configurator
            ->method('set')
            ->with($id, $class)
            ->willReturn($this->createServiceConfiguratorMock());
        $this->configurator
            ->expects($this->once())
            ->method('alias')
            ->with($class, $id);

        $this->helper->setService($class, true);
    }

    public function testSetAlias(): void
    {
        $id = 'std_class';
        $class = stdClass::class;

        $this->configurator
            ->expects($this->once())
            ->method('alias')
            ->with($class, $id);

        $this->helper->setAlias($class, $id);
    }

    private function createServicesConfiguratorMock(): ServicesConfigurator
    {
        return $this->getMockBuilder(ServicesConfigurator::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    private function createServiceConfiguratorMock(): ServiceConfigurator
    {
        return $this->getMockBuilder(ServiceConfigurator::class)
            ->disableOriginalConstructor()
            ->getMock();
    }
}
