<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Symfony\Integration\OtelSdkBundle\DependencyInjection;

use Exception;
use OpenTelemetry\Contrib\Otlp\SpanExporterFactory as OtlpExporterFactory;
use OpenTelemetry\Contrib\Zipkin\SpanExporterFactory as ZipkinSpanExporterFactory;
use OpenTelemetry\SDK;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Time\SystemClock;
use OpenTelemetry\SDK\Trace\SpanProcessor;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\OtelSdkExtension;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Parameters;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Samplers;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\SpanProcessors;
use OpenTelemetry\Symfony\OtelSdkBundle\Util\ConfigHelper;
use OpenTelemetry\Symfony\OtelSdkBundle\Util\ServiceHelper;
use OpenTelemetry\Tests\Symfony\Integration\OtelSdkBundle\Mock;
use OpenTelemetry\Tests\Symfony\Integration\OtelSdkBundle\Mock\SpanExporterFactory as MockSpanExporterFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Yaml\Parser;

class OtelSdkExtensionTest extends TestCase
{
    private const ROOT = 'otel_sdk';
    private const CUSTOM_SAMPLER_ID = 'my_custom_sampler';
    private const CUSTOM_EXPORTER_ID = 'my_custom_exporter';
    private const CUSTOM_PROCESSOR_ID = 'my_span_processor';

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

    public function testResourceLimits(): void
    {
        $data = $this->loadTestData('resource');

        $spanLimitsBuilder = $this->getDefinitionByClass(SDK\Trace\SpanLimitsBuilder::class);

        $this->assertEquals(
            array_values($data['resource']['limits']),
            [
                $spanLimitsBuilder->getMethodCalls()[0][1][0],
                $spanLimitsBuilder->getMethodCalls()[1][1][0],
            ]
        );
    }

    public function testResourceAttributes(): void
    {
        $data = $this->loadTestData('resource');
        $params = $this->getContainer()
            ->getParameterBag()
            ->all();

        $this->assertEquals(
            $data['resource']['attributes'],
            $params[Parameters::RESOURCE_ATTRIBUTES]
        );

        $arguments = $this->getDefinitionByClass(Attributes::class)
            ->getArguments();

        $this->assertSame(
            ConfigHelper::wrapParameter(Parameters::RESOURCE_ATTRIBUTES),
            $arguments[0]
        );
        $this->assertEquals(0, $arguments[1]);
    }

    public function testDefaultSampler(): void
    {
        $this->loadTestData('minimal');

        $this->assertReference(
            Samplers::DEFAULT_SAMPLER,
            $this->getDefinitionByClass(SDK\Trace\Sampler\ParentBased::class)
                ->getArguments()[0]
        );
    }

    public function testSamplers(): void
    {
        $this->loadTestData('sampler');

        $sampler = $this->getDefinitionByClass(SDK\Trace\TracerProvider::class)
            ->getArgument(1);

        $this->assertReference(
            SDK\Trace\Sampler\ParentBased::class,
            $sampler
        );

        $parent = $this->getDefinitionByClass(SDK\Trace\Sampler\ParentBased::class);
        $arguments = $parent->getArguments();

        $this->assertReference(
            Mock\Sampler::class,
            $arguments[0]
        );

        $this->assertReference(
            SDK\Trace\Sampler\TraceIdRatioBasedSampler::class,
            $arguments[1],
            '.0.5'
        );

        $this->assertReference(
            SDK\Trace\Sampler\AlwaysOffSampler::class,
            $arguments[2]
        );

        $this->assertSame(
            self::CUSTOM_SAMPLER_ID,
            (string) $arguments[3]
        );

        $this->assertReference(
            SDK\Trace\Sampler\AlwaysOffSampler::class,
            $arguments[4]
        );
    }

    public function testAlwaysOffRootSamplerRegression(): void
    {
        $data = $this->retrieveTestData('simple');
        $data['trace']['sampler'] = 'always_off';

        $this->load(
            self::wrapConfig(
                $data
            )
        );

        $sampler = $this->getDefinitionByClass(SDK\Trace\TracerProvider::class)
            ->getArgument(1);

        $this->assertReference(
            SDK\Trace\Sampler\ParentBased::class,
            $sampler
        );

        $parent = $this->getDefinitionByClass(SDK\Trace\Sampler\ParentBased::class);
        $arguments = $parent->getArguments();

        $this->assertReference(
            SDK\Trace\Sampler\AlwaysOffSampler::class,
            $arguments[0]
        );
    }

    /**
     * @throws Exception
     * @psalm-suppress PossiblyNullArrayAccess
     */
    public function testSpanLimits(): void
    {
        $data = $this->loadTestData('span')['trace']['span']['limits'];
        $limits = $this->getDefinitionByClass(SDK\Trace\SpanLimits::class);
        $builder = $this->getDefinitionByClass(SDK\Trace\SpanLimitsBuilder::class);

        $this->assertReference(
            SDK\Trace\SpanLimitsBuilder::class,
            $limits->getFactory()[0]
        );
        $this->assertSame(
            'build',
            $limits->getFactory()[1]
        );

        // test limit builder method calls
        $calls = [];
        foreach ($builder->getMethodCalls() as $call) {
            $calls[$call[0]] = $call[1][0];
        }
        foreach ($data as $attr => $value) {
            $setter = sprintf('set%s', Container::camelize($attr));
            $this->assertArrayHasKey($setter, $calls);
            $this->assertSame(
                $calls[$setter],
                $value
            );
        }
    }

    public function testEmptySpanProcessor(): void
    {
        $this->loadTestData('minimal');

        $this->assertReference(
            SpanProcessors::NOOP,
            $this->getDefinitionByClass(SDK\Trace\TracerProvider::class)
                ->getArgument(0)
        );
    }

    /**
     * @throws Exception
     */
    public function testSpanProcessors(): void
    {
        $this->loadTestData('full');

        $processors = $this->getDefinitionByClass(SDK\Trace\TracerProvider::class)
            ->getArgument(0);

        $this->assertIsReferenceForClass(
            SpanProcessors::DEFAULT,
            $processors[0]
        );
        $this->assertIsReferenceForClass(
            SpanProcessors::SIMPLE,
            $processors[1]
        );
        $this->assertIsReferenceForClass(
            Mock\SpanProcessor::class,
            $processors[2]
        );
        $this->assertIsReferenceForClass(
            Mock\SpanProcessor::class,
            $processors[3]
        );

        // Assert clock is set as argument for batch processors
        $batchProcessors = 0;
        foreach ($processors as $processorReference) {
            if ($this->getClassFromReference($processorReference) === SpanProcessor\BatchSpanProcessor::class) {
                $definition = $this->container->getDefinition((string) $processorReference);
                $this->assertIsReferenceForClass(
                    SystemClock::class,
                    $definition->getArgument(1)
                );
                $batchProcessors++;
            }
        }
        if ($batchProcessors === 0) {
            $this->fail('One of the tested span processors must be a batch processor');
        }
    }

    public function testDebugSpanProcessors(): void
    {
        $this->container->setParameter('kernel.environment', 'dev');
        $this->loadTestData('full');

        $processors = $this->getDefinitionByClass(SDK\Trace\TracerProvider::class)
            ->getArgument(0);

        $this->assertIsReferenceForClass(
            SpanProcessors::TRACEABLE,
            $processors[0]
        );
        $this->assertIsReferenceForClass(
            SpanProcessors::TRACEABLE,
            $processors[1]
        );
        $this->assertIsReferenceForClass(
            SpanProcessors::TRACEABLE,
            $processors[2]
        );
        $this->assertIsReferenceForClass(
            SpanProcessors::TRACEABLE,
            $processors[3]
        );
    }

    /**
     * @psalm-suppress PossiblyInvalidCast
     * @psalm-suppress RedundantCondition
     * @psalm-suppress InvalidArrayOffset
     * @psalm-suppress PossiblyNullArrayAccess
     * @psalm-suppress PossiblyNullArgument
     */
    public function testSpanExporters(): void
    {
        $data = $this->loadTestData('exporters')['trace']['exporters'];
        $exporterIds = [];
        foreach ($data as $key => $conf) {
            $exporterIds[] = isset($conf['id'])
                ? str_replace('@', '', $conf['id'])
                :'open_telemetry.sdk.trace.span_exporter.' . $key;
        }

        $exporters = [];
        $processorReferences = $this->getDefinitionByClass(SDK\Trace\TracerProvider::class)
            ->getArgument(0);
        foreach ($processorReferences as $index => $reference) {
            $processor = $this->container->getDefinition((string) $reference);
            $exporterRef = $processor->getArgument(0);
            $this->assertIsReference($exporterRef);
            $exporter = $exporters[(string) $exporterRef] = $this->getContainer()->getDefinition((string) $exporterRef);

            if ((string) $exporterRef !== self::CUSTOM_EXPORTER_ID) {
                switch ($reference) {
                    default:
                        $expectClass = null;

                        break;

                    case 'open_telemetry.sdk.trace.span_processor.default.exporter1':
                        $expectClass = ZipkinSpanExporterFactory::class;

                        break;

                    case 'open_telemetry.sdk.trace.span_processor.simple.exporter2':
                        $expectClass = OtlpExporterFactory::class;

                        break;

                    case 'open_telemetry.sdk.trace.span_processor.default.exporter3':
                        $expectClass = MockSpanExporterFactory::class;

                        break;
                }

                $this->assertIsReference(
                    $exporter->getFactory()[0],
                );
                $this->assertIsReferenceForClass(
                    $expectClass,
                    $exporter->getFactory()[0],
                    'processor index ' . $index . ' reference ' . $reference
                );
            }
        }

        $this->assertEquals(
            $exporterIds,
            array_keys($exporters)
        );
    }

    // HELPER METHODS

    /**
     * @psalm-suppress PossiblyInvalidCast
     */
    private function assertReference(string $class, ?object $reference, ?string $idSuffix = null): void
    {
        $this->assertIsReference(
            $reference
        );

        $id = ServiceHelper::classToId($class) . (string) $idSuffix;
        $this->assertSame(
            $id,
            (string) $reference
        );

        $this->assertSame(
            $class,
            $this->getContainer()
                ->getDefinition($id)
                ->getClass()
        );
    }

    private function assertIsReferenceForClass(string $class, Reference $reference, string $message = ''): void
    {
        $this->assertSame(
            $class,
            $this->getClassFromReference($reference),
            $message
        );
    }

    private function assertIsReference(?object $reference): void
    {
        $this->assertInstanceOf(
            Reference::class,
            $reference
        );
    }

    /**
     * @psalm-suppress NullableReturnStatement
     * @psalm-suppress InvalidNullableReturnType
     */
    private function getClassFromReference(Reference $reference): string
    {
        return $this->getContainer()
            ->getDefinition((string) $reference)
            ->getClass();
    }

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
        return new OtelSdkExtension();
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
     * @param string $class
     * @psalm-param class-string $class
     * @throws Exception
     * @return Definition
     */
    private function getDefinitionByClass(string $class): Definition
    {
        $definition =  $this->getContainer()->getDefinition(ServiceHelper::classToId($class));
        $this->assertSame(
            $class,
            $definition->getClass()
        );

        return $definition;
    }

    private static function wrapConfig(array $config): array
    {
        return [self::ROOT => $config];
    }

    private function retrieveTestData(string $variant): array
    {
        return $this->loadYamlFile(__DIR__ . '/config/' . $variant . '/config.yaml');
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
