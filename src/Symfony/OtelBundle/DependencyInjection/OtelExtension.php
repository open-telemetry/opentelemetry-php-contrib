<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelBundle\DependencyInjection;

use OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use OpenTelemetry\Symfony\OtelBundle\HttpKernel\RequestListener;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

final class OtelExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $config = $this->processConfiguration($this->getConfiguration($configs, $container), $configs);

        $loader = new PhpFileLoader($container, new FileLocator());
        $loader->load(__DIR__ . '/../Resources/services.php');

        if ($config['tracing']['kernel']['enabled'] ?? false) {
            $loader->load(__DIR__ . '/../Resources/services_kernel.php');
            $this->loadKernelTracing($config['tracing']['kernel'], $container);
        }
    }

    private function loadKernelTracing(array $config, ContainerBuilder $container): void
    {
        $container->getDefinition(RequestListener::class)
            ->setArgument('$requestHeaders', $config['requestHeaders'])
            ->setArgument('$responseHeaders', $config['responseHeaders'])
        ;

        if (!$config['extractRemoteContext']) {
            $container->getDefinition(RequestListener::class)
                ->setArgument('$propagator', new Reference(NoopTextMapPropagator::class))
            ;
        }
    }

    public function getConfiguration(array $config, ContainerBuilder $container): ConfigurationInterface
    {
        return new Configuration();
    }
}
