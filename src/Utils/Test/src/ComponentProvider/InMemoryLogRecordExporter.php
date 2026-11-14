<?php

declare(strict_types=1);

namespace OpenTelemetry\TestUtils\ComponentProvider;

use OpenTelemetry\API\Configuration\Config\ComponentProvider;
use OpenTelemetry\API\Configuration\Config\ComponentProviderRegistry;
use OpenTelemetry\API\Configuration\Context;
use OpenTelemetry\SDK\Common\Export\InMemoryStorageManager;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;

/**
 * @implements ComponentProvider<LogRecordExporterInterface>
 */
final class InMemoryLogRecordExporter implements ComponentProvider
{
    #[\Override]
    public function createPlugin(array $properties, Context $context): LogRecordExporterInterface
    {
        return new InMemoryExporter(InMemoryStorageManager::logs());
    }

    /**
     * @phan-suppress PhanUndeclaredTypeParameter,PhanUndeclaredTypeReturnType,PhanUndeclaredClassMethod
     */
    #[\Override]
    public function getConfig(ComponentProviderRegistry $registry, NodeBuilder $builder): ArrayNodeDefinition
    {
        return $builder->arrayNode('memory/test_utils');
    }
}
