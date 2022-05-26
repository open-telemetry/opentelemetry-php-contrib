<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelInstrumentationBundle\DependencyInjection;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

/**
 * @codeCoverageIgnore
 */
class OtelInstrumentationExtension extends Extension implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @param array $configs
     * @param ContainerBuilder $container
     */
    final public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();

        $config = $this->processConfiguration($configuration, $configs);
    }
}
