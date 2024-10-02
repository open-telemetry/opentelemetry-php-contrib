<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\ComponentProvider;

use OpenTelemetry\Config\SDK\Configuration\ComponentProvider;
use OpenTelemetry\Config\SDK\Configuration\ComponentProviderRegistry;
use OpenTelemetry\Config\SDK\Configuration\Context;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\TestStorage;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

final class SpanExporterInMemory implements ComponentProvider
{
    public function createPlugin(array $properties, Context $context): SpanExporterInterface
    {
        return new InMemoryExporter(TestStorage::getInstance());
    }

    public function getConfig(ComponentProviderRegistry $registry): ArrayNodeDefinition
    {
        return new ArrayNodeDefinition('test/in_memory_exporter');
    }
}
