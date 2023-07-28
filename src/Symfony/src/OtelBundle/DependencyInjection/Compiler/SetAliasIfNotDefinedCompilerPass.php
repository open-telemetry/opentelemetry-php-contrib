<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Symfony\OtelBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class SetAliasIfNotDefinedCompilerPass implements CompilerPassInterface
{
    private string $service;
    private string $aliasService;

    public function __construct(string $service, string $aliasService)
    {
        $this->service = $service;
        $this->aliasService = $aliasService;
    }

    public function process(ContainerBuilder $container): void
    {
        if ($container->has($this->service)) {
            return;
        }

        $container->setAlias($this->service, $this->aliasService);
    }
}
