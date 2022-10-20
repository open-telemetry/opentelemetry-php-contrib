<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\Test\Unit\OtelBundle\DependencyInjection\Compiler;

use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractCompilerPassTestCase;
use OpenTelemetry\Symfony\OtelBundle\DependencyInjection\Compiler\SetAliasIfNotDefinedCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class SetAliasIfNotDefinedCompilerPassTest extends AbstractCompilerPassTestCase
{
    protected function registerCompilerPass(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new SetAliasIfNotDefinedCompilerPass('service', 'aliasService'));
    }

    public function testSetsServiceAliasIfServiceNotSet(): void
    {
        $this->container->register('aliasService', 'AliasService');
        $this->compile();

        $this->assertContainerBuilderHasService('service', 'AliasService');
        $this->assertContainerBuilderHasAlias('service', 'aliasService');
    }

    public function testDoesNotSetServiceAliasIfServiceSet(): void
    {
        $this->container->register('aliasService', 'AliasService');
        $this->container->register('service', 'Service');
        $this->compile();

        $this->assertContainerBuilderHasService('service', 'Service');
    }
}
