<?php

declare(strict_types=1);

namespace OpenTelemetry\TestUtils\ComponentProvider;

use OpenTelemetry\API\Configuration\Config\ComponentProvider;
use OpenTelemetry\API\Configuration\Config\ComponentProviderRegistry;
use OpenTelemetry\API\Configuration\Context;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\TestUtils\Exporters\InMemoryStorageManager;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;

/**
 * @implements ComponentProvider<MetricExporterInterface>
 */
final class InMemoryMetricsExporter implements ComponentProvider
{
    #[\Override]
    public function createPlugin(array $properties, Context $context): MetricExporterInterface
    {
        return new InMemoryExporter(InMemoryStorageManager::metrics());
    }

    #[\Override]
    public function getConfig(ComponentProviderRegistry $registry, NodeBuilder $builder): ArrayNodeDefinition
    {
        return $builder->arrayNode('test/in_memory_exporter');
    }
}
