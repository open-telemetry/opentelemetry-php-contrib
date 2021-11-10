<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\Util;

use Symfony\Component\DependencyInjection\Loader\Configurator\ServiceConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

class ServicesConfiguratorHelper
{
    private ServicesConfigurator $configurator;

    /**
     * @param ServicesConfigurator $configurator
     */
    public function __construct(ServicesConfigurator $configurator)
    {
        $this->configurator = $configurator;
    }

    /**
     * @param ServicesConfigurator $configurator
     * @return self
     */
    public static function create(ServicesConfigurator $configurator): self
    {
        return new self($configurator);
    }

    /**
     * @param string $class
     * @psalm-param class-string $class
     * @param bool $alias
     * @return ServiceConfigurator
     */
    public function setService(string $class, bool $alias = true): ServiceConfigurator
    {
        $id = ServiceHelper::classToId($class);
        $serviceConfigurator = $this->getConfigurator()->set($id, $class);
        if ($alias === true) {
            $this->setAlias($class, $id);
        }

        return $serviceConfigurator;
    }

    /**
     * @param string $id
     * @param string $referencedId
     */
    public function setAlias(string $id, string $referencedId): void
    {
        $this->getConfigurator()->alias($id, $referencedId);
    }

    /**
     * @return ServicesConfigurator
     */
    public function getConfigurator(): ServicesConfigurator
    {
        return $this->configurator;
    }
}
