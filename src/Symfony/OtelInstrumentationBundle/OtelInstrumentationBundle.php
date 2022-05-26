<?php

/** @noinspection SpellCheckingInspection */

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelInstrumentationBundle;

use OpenTelemetry\Symfony\OtelInstrumentationBundle\DependencyInjection\Compiler\HttpClientCompilerPass;
use OpenTelemetry\Symfony\OtelInstrumentationBundle\DependencyInjection\OtelInstrumentationExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class OtelInstrumentationBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__));
        $loader->load('Resources/config/services.yml');

        $container->addCompilerPass(new HttpClientCompilerPass());
    }

    public function getContainerExtension(): OtelInstrumentationExtension
    {
        return new OtelInstrumentationExtension();
    }
}
