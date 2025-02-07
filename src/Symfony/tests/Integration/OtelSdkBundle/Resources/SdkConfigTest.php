<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Symfony\Integration\OtelSdkBundle\Resources;

use Exception;
use OpenTelemetry\API\Common\Time\SystemClock;
use OpenTelemetry\Contrib\Symfony\OtelSdkBundle\DependencyInjection\OtelSdkExtension;
use OpenTelemetry\Contrib\Symfony\OtelSdkBundle\DependencyInjection\Parameters;
use OpenTelemetry\Contrib\Symfony\OtelSdkBundle\Util\ServiceHelper;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource;
use OpenTelemetry\SDK\Trace;
use OpenTelemetry\SDK\Trace\Sampler;
use OpenTelemetry\SDK\Trace\SpanProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

class SdkConfigTest extends TestCase
{
    private const CONFIG_FILE = OtelSdkExtension::SDK_CONFIG_FILE;

    private ContainerBuilder $container;

    /**
     * @throws Exception
     */
    public function setUp(): void
    {
        $this->container = new ContainerBuilder();
        (new PhpFileLoader(
            $this->container,
            new FileLocator()
        ))->load(self::CONFIG_FILE);

        foreach ($this->container->getDefinitions() as $definition) {
            $definition->setPublic(true);
        }
        foreach ($this->container->getAliases() as $alias) {
            $alias->setPublic(true);
        }

        $this->container->compile();
    }

    /**
     * @test
     * @throws Exception
     */
    public function testUtil()
    {
        $this->assertServiceSetup(SystemClock::class);
        $this->assertServiceSetup(Trace\RandomIdGenerator::class);
    }

    /**
     * @test
     * @throws Exception
     */
    public function testResource()
    {
        $this->assertServiceSetup(Attributes::class);
        $this->assertServiceSetup(Resource\ResourceInfo::class);
    }

    /**
     * @test
     * @throws Exception
     */
    public function testSampler()
    {
        $this->assertServiceSetup(Sampler\AlwaysOnSampler::class);
        $this->assertServiceSetup(Sampler\AlwaysOffSampler::class);
        $this->assertServiceSetup(Sampler\TraceIdRatioBasedSampler::class);
        $this->assertServiceSetup(Sampler\ParentBased::class);

        $this->assertSame(
            $this->getByClass(Sampler\TraceIdRatioBasedSampler::class),
            $this->get(
                sprintf(
                    '%s.%s',
                    ServiceHelper::classToId(Sampler\TraceIdRatioBasedSampler::class),
                    Parameters::DEFAULT_SAMPLER_TRACE_ID_RATIO_BASED_DEFAULT_RATIO
                )
            )
        );
    }

    /**
     * @test
     * @throws Exception
     */
    public function testSpan()
    {
        $this->assertServiceSetup(Trace\SpanLimitsBuilder::class);
        $this->assertServiceSetup(Trace\SpanLimits::class);
        $this->assertServiceSetup(SpanProcessor\NoopSpanProcessor::class);
        $this->assertServiceSetup(SpanProcessor\MultiSpanProcessor::class);
    }

    /**
     * @test
     * @throws Exception
     */
    public function testTracer()
    {
        $this->assertServiceSetup(Trace\TracerProvider::class);
    }

    /**
     * @param string $id
     * @throws Exception
     * @return object|null
     */
    private function get(string $id): ?object
    {
        return $this->container->get($id);
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

    /**
     * @param string $class
     * @psalm-param class-string $class
     * @throws Exception
     */
    private function assertServiceSetup(string $class)
    {
        $service = $this->get($class);
        $this->assertInstanceOf($class, $service);
        $this->assertSame(
            $service,
            $this->getByClass($class)
        );
    }
}
