<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\Test\Unit\OtelBundle\DependencyInjection;

use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractExtensionConfigurationTestCase;
use OpenTelemetry\Symfony\OtelBundle\DependencyInjection\Configuration;
use OpenTelemetry\Symfony\OtelBundle\DependencyInjection\OtelExtension;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

/**
 * @covers \OpenTelemetry\Symfony\OtelBundle\DependencyInjection\Configuration
 */
final class ConfigurationTest extends AbstractExtensionConfigurationTestCase
{
    protected function getContainerExtension(): ExtensionInterface
    {
        return new OtelExtension();
    }

    protected function getConfiguration(): ConfigurationInterface
    {
        return new Configuration();
    }

    /**
     * @dataProvider sourcesProvider
     */
    public function testConfiguration(array $sources): void
    {
        $expectedConfiguration = [
            'tracing' => [
                'http' => [
                    'server' => [
                        'requestHeaders' => [
                            'Content-Length',
                            'date',
                        ],
                        'responseHeaders' => [
                            'link',
                        ],
                    ],
                ],
                'console' => [
                    'enabled' => true,
                ],
                'kernel' => [
                    'enabled' => true,
                    'extractRemoteContext' => true,
                ],
            ],
        ];

        $this->assertProcessedConfigurationEquals($expectedConfiguration, $sources);
    }

    public function sourcesProvider(): iterable
    {
        yield [[__DIR__ . '/Fixtures/config.yaml']];
        yield [[__DIR__ . '/Fixtures/config.xml']];
    }
}
