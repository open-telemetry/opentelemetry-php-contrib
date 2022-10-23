<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Symfony\Integration\OtelSdkBundle;

use Exception;
use OpenTelemetry\API;
use OpenTelemetry\Contrib;
use OpenTelemetry\SDK;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\OtelSdkExtension;
use OpenTelemetry\Symfony\OtelSdkBundle\OtelSdkBundle;
use OpenTelemetry\Symfony\OtelSdkBundle\Util\ServiceHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Yaml\Parser;

class OtelSdkBundleTest extends TestCase
{
    private const ROOT = 'otel_sdk';
    private const CUSTOM_SAMPLER_ID = 'my_custom_sampler';
    private const CUSTOM_EXPORTER_ID = 'my_custom_exporter';
    private const CUSTOM_PROCESSOR_ID = 'my_span_processor';
    private const DEFAULT_SPAN_PROCESSOR_ID = 'open_telemetry.sdk.trace.span_processor.default.0';
    private const DEFAULT_SPAN_EXPORTER_ID = 'open_telemetry.sdk.trace.span_exporter.0';
    private const HTTP_CLIENT_MOCK_ID = 'http_client_mock';

    private static ?Parser $parser = null;

    private OtelSdkExtension $extension;
    private ContainerBuilder $container;

    public function setUp(): void
    {
        $this->extension = $this->createExtension();
        $this->container = $this->createContainer();
        $this->container->setParameter('kernel.debug', false);
        $this->container->setParameter('kernel.environment', 'prod');
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
     * @test
     * @throws Exception
     */
    public function testWithMinimalConfig(): void
    {
        $this->loadTestData('minimal');

        $provider = $this->getByClass(SDK\Trace\TracerProvider::class);
        $this->assertInstanceOf(
            SDK\Trace\TracerProvider::class,
            $provider
        );

        $tracer = $provider->getTracer('foo');
        $this->assertInstanceOf(
            API\Trace\TracerInterface::class,
            $tracer
        );

        $sampler = $provider->getSampler();
        $this->assertInstanceOf(
            SDK\Trace\Sampler\AlwaysOnSampler::class,
            $sampler
        );
    }

    /**
     * @test
     * @throws Exception
     */
    public function testTracerProviderWithSimpleConfig(): void
    {
        $this->loadTestData('simple');

        $this->assertInstanceOf(
            SDK\Trace\TracerProvider::class,
            $this->getTracerProvider()
        );
    }

    /**
     * @test
     * @depends testTracerProviderWithSimpleConfig
     * @throws Exception
     */
    public function testSamplerWithSimpleConfig(): void
    {
        $this->loadTestData('simple');

        $this->assertInstanceOf(
            SDK\Trace\Sampler\ParentBased::class,
            $this->getTracerProvider()
                ->getSampler()
        );
    }

    /**
     * @test
     * @depends testTracerProviderWithSimpleConfig
     * @throws Exception
     */
    public function testProcessorWithSimpleConfig(): void
    {
        $this->loadTestData('simple');

        $processorId = (string) $this->container->getDefinition(
            $this->getTracerProviderId()
        )->getArgument(0)[0];

        $this->assertSame(
            self::DEFAULT_SPAN_PROCESSOR_ID,
            $processorId
        );

        $this->assertInstanceOf(
            SDK\Trace\SpanProcessor\BatchSpanProcessor::class,
            $this->get($processorId)
        );
    }

    /**
     * @test
     * @depends testProcessorWithSimpleConfig
     * @throws Exception
     */
    public function testExporterWithSimpleConfig(): void
    {
        $this->loadTestData('simple');

        $processorId = (string) $this->container->getDefinition(
            $this->getTracerProviderId()
        )->getArgument(0)[0];
        $exporterId = (string) $this->container->getDefinition($processorId)->getArgument(0);

        $this->assertSame(
            self::DEFAULT_SPAN_EXPORTER_ID,
            $exporterId
        );

        $exporter = $this->container->get($exporterId);

        $this->assertInstanceOf(
            Contrib\Zipkin\Exporter::class,
            $exporter
        );
    }

    /**
     * @depends testExporterWithSimpleConfig
     * @throws Exception
     * @psalm-suppress PossiblyUndefinedMethod
     */
    public function testTracingWithSimpleConfig(): void
    {
        $this->loadTestData('simple');

        $this->registerHttpClientMock()->expects($this->once())
            ->method('sendRequest');

        $exporter = $this->container->getDefinition(self::DEFAULT_SPAN_EXPORTER_ID);
        $options = $exporter->getArgument(0);
        $options['client'] = $this->createReference(self::HTTP_CLIENT_MOCK_ID);
        $exporter->setArgument(0, $options);

        $this->getTracerProvider()
            ->getTracer('foo')
            ->spanBuilder('bar')
            ->startSpan()
            ->end();

        $this->getTracerProvider()->forceFlush();
    }

    /**
     * @depends testExporterWithSimpleConfig
     * @throws Exception
     * @psalm-suppress PossiblyUndefinedMethod
     */
    public function testTracingWithAlwaysOffSampler(): void
    {
        $this->load(
            self::wrapConfig([
                'resource' => [
                    'attributes' => [
                        'service.name' => 'foo',
                    ],
                ],
                'trace' => [
                    'sampler' => 'always_off',
                    'exporters' => ['zipkin+http://zipkinhost:1234/path'],
                ],
            ])
        );

        $this->registerHttpClientMock()->expects($this->never())
            ->method('sendRequest');

        $exporter = $this->container->getDefinition(self::DEFAULT_SPAN_EXPORTER_ID);
        $options = $exporter->getArgument(0);
        $options['client'] = $this->createReference(self::HTTP_CLIENT_MOCK_ID);
        $exporter->setArgument(0, $options);

        $this->getTracerProvider()
            ->getTracer('foo')
            ->spanBuilder('bar')
            ->startSpan()
            ->end();

        $this->getTracerProvider()->forceFlush();
    }

    /**
     * @param string $id
     * @return Reference
     */
    private function createReference(string $id)
    {
        return new Reference($id);
    }

    /**
     * @throws Exception
     * @return SDK\Trace\TracerProvider
     */
    private function getTracerProvider(): SDK\Trace\TracerProvider
    {
        /** @var  SDK\Trace\TracerProvider */
        return $this->get($this->getTracerProviderId());
    }

    /**
     * @return string
     */
    private function getTracerProviderId(): string
    {
        return ServiceHelper::classToId(SDK\Trace\TracerProvider::class);
    }

    /**
     * @return MockObject|ClientInterface
     * @psalm-suppress MismatchingDocblockReturnType
     */
    private function registerHttpClientMock(): ClientInterface
    {
        $mock = $this->createMock(ClientInterface::class);
        $this->container->set(self::HTTP_CLIENT_MOCK_ID, $mock);

        return $mock;
    }

    /**
     * @param string $variant
     * @return array
     */
    private function loadTestData(string $variant): array
    {
        $data = $this->retrieveTestData($variant);
        $this->load(
            self::wrapConfig(
                $data
            )
        );

        return $data;
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
     * @return OtelSdkExtension
     */
    private static function createExtension(): OtelSdkExtension
    {
        return (new OtelSdkBundle())->getContainerExtension();
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

    /**
     * @param string $id
     * @throws Exception
     * @return object|null
     */
    private function get(string $id): ?object
    {
        return $this->getContainer()->get($id);
    }

    /**
     * @param string $class
     * @psalm-param class-string $class
     * @throws Exception
     * @return object|null
     */
    private function getByClass(string $class): ?object
    {
        return $this->get(ServiceHelper::classToId($class));
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
