<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Integration\Symfony\OtelInstrumentationBundle;

use OpenTelemetry\Symfony\OtelInstrumentationBundle\DependencyInjection\OtelInstrumentationExtension;
use OpenTelemetry\Symfony\OtelInstrumentationBundle\OtelInstrumentationBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Yaml\Parser;

class OtelInstrumentationBundleTest extends TestCase
{
    private const ROOT = 'otel_sdk';
    private const CUSTOM_SAMPLER_ID = 'my_custom_sampler';
    private const CUSTOM_EXPORTER_ID = 'my_custom_exporter';
    private const CUSTOM_PROCESSOR_ID = 'my_span_processor';
    private const DEFAULT_SPAN_PROCESSOR_ID = 'open_telemetry.sdk.trace.span_processor.default.0';
    private const DEFAULT_SPAN_EXPORTER_ID = 'open_telemetry.sdk.trace.span_exporter.0';
    private const HTTP_CLIENT_MOCK_ID = 'http_client_mock';

    private static ?Parser $parser = null;

    private OtelInstrumentationExtension $extension;
    private ContainerBuilder $container;

    public function setUp(): void
    {
        $this->extension = $this->createExtension();
        $this->container = $this->createContainer();
        $this->container->setParameter('kernel.debug', false);
        $this->container->setDefinition(
            self::CUSTOM_SAMPLER_ID,
            new Definition(Mock\Sampler::class)
        );
        $this->container->setDefinition(
            self::CUSTOM_EXPORTER_ID,
            new Definition(Mock\SpanExporter::class)
        );
        $this->container->setDefinition(
            self::CUSTOM_PROCESSOR_ID,
            new Definition(Mock\SpanProcessor::class)
        );
    }

    /**
     * @param array $config
     * @return ContainerBuilder
     */
    private function load(array $config): ContainerBuilder
    {
        $this->extension->load(
            $config,
            $this->getContainer()
        );

        foreach ($this->getContainer()->getDefinitions() as $definition) {
            $definition->setPublic(true);
        }

        return $this->getContainer();
    }

    /**
     * @return OtelInstrumentationExtension
     */
    private static function createExtension(): OtelInstrumentationExtension
    {
        return (new OtelInstrumentationBundle())->getContainerExtension();
    }

    /**
     * @return ContainerBuilder
     */
    private function getContainer(): ContainerBuilder
    {
        return $this->container;
    }

    /**
     * @return ContainerBuilder
     */
    private static function createContainer(): ContainerBuilder
    {
        return new ContainerBuilder();
    }

    private static function wrapConfig(array $config): array
    {
        return [self::ROOT => $config];
    }

    private function retrieveTestData(string $variant): array
    {
        return $this->loadYamlFile(__DIR__ . '/DependencyInjection/config/' . $variant . '/config.yaml');
    }

    private function loadYamlFile(string $file): array
    {
        return self::getParser()->parseFile($file);
    }

    private static function getParser(): Parser
    {
        return self::$parser ?? self::$parser = new Parser();
    }
}
