<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Symfony\Integration\OtelSdkBundle\DependencyInjection;

use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\ConfigurationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\Yaml\Parser;

class ConfigurationTest extends TestCase
{
    private const CONFIG_VARIANTS = [
        'minimal',
        'simple',
        'resource',
        'sampler',
        'span',
        'exporters',
        'disabled',
        'full',
    ];

    private const EXCEPTIONS = [
        'service_name',
        'sampler_type',
        'processor_type',
        'exporter_dsn',
        'exporter_key',
        'custom_no_class_or_id',
        'custom_class_and_id',
        'custom_not_found',
        'custom_no_interface',
    ];

    private static ?Parser $parser = null;
    private NodeInterface $treeNode;

    public function setUp(): void
    {
        $this->treeNode = (new Configuration())
            ->getConfigTreeBuilder()
            ->buildTree();
    }

    /**
     * @dataProvider configProvider
     *
     * @param mixed $inputConfig
     * @param mixed $expectedConfig
     */
    public function testConfiguration($inputConfig, $expectedConfig)
    {
        $finalizedConfig = $this->treeNode->finalize(
            $this->treeNode->normalize($inputConfig)
        );

        $this->assertEquals($expectedConfig, $finalizedConfig);
    }

    /**
     * @dataProvider exceptionProvider
     *
     * @param mixed $inputConfig
     */
    public function testException($inputConfig)
    {
        $this->expectException(
            ConfigurationException::class
        );

        $this->treeNode->finalize(
            $this->treeNode->normalize($inputConfig)
        );
    }

    public function configProvider(): array
    {
        $data = [];

        foreach (self::CONFIG_VARIANTS as $variant) {
            $data[$variant] = $this->loadTestData($variant);
        }

        return $data;
    }

    public function exceptionProvider(): array
    {
        $data = [];

        foreach (self::EXCEPTIONS as $variant) {
            $data[$variant] = $this->loadExceptionData($variant);
        }

        return $data;
    }

    private function loadTestData(string $variant): array
    {
        return [
            $this->load(__DIR__ . '/config/' . $variant . '/config.yaml'),
            $this->load(__DIR__ . '/config/' . $variant . '/expected.yaml'),
        ];
    }

    private function loadExceptionData(string $variant): array
    {
        return [
            $this->load(__DIR__ . '/config/_exceptions/' . $variant . '.yaml'),
        ];
    }

    private function load(string $file): array
    {
        return self::getParser()->parseFile($file);
    }

    private static function getParser(): Parser
    {
        return self::$parser ?? self::$parser = new Parser();
    }
}
