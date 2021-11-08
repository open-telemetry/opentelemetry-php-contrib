<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\Util;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServiceConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

class ContainerConfiguratorHelper
{
    private ContainerConfigurator $containerConfigurator;

    /**
     * @param ContainerConfigurator $containerConfigurator
     */
    public function __construct(ContainerConfigurator $containerConfigurator)
    {
        $this->containerConfigurator = $containerConfigurator;
    }

    /**
     * @param ContainerConfigurator $containerConfigurator
     * @return self
     */
    public static function create(ContainerConfigurator $containerConfigurator): self
    {
        return new self($containerConfigurator);
    }

    /**
     * @param string $class
     * @param bool $alias
     * @psalm-param class-string $class
     * @return ServiceConfigurator
     */
    public function setService(string $class, bool $alias = true): ServiceConfigurator
    {
        $id = ServiceHelper::classToId($class);
        $service = $this->getServicesConfigurator()->set($id, $class);
        if ($alias === true) {
            $this->getServicesConfigurator()->alias($class, $id);
        }

        return $service;
    }

    /**
     * @return ServicesConfigurator
     */
    public function getServicesConfigurator(): ServicesConfigurator
    {
        return $this->containerConfigurator->services();
    }
}
