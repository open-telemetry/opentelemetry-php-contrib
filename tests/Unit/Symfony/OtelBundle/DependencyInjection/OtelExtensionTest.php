<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelBundle\DependencyInjection;

use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractExtensionTestCase;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Symfony\OtelBundle\DependencyInjection\OtelExtension;
use OpenTelemetry\Symfony\OtelBundle\HttpKernel\RequestListener;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @covers \OpenTelemetry\Symfony\OtelBundle\DependencyInjection\OtelExtension
 */
final class OtelExtensionTest extends AbstractExtensionTestCase
{
    protected function getContainerExtensions(): array
    {
        return [
            new OtelExtension(),
        ];
    }

    public function testEmptyConfigEnablesKernelTracing(): void
    {
        $this->load();

        $this->assertContainerBuilderHasService(RequestListener::class);
    }

    public function testKernelTracingCanBeDisabled(): void
    {
        $this->load([
            'tracing' => [
                'kernel' => false,
            ],
        ]);

        $this->assertContainerBuilderNotHasService(RequestListener::class);
    }

    public function testNoopOtelServicesAreAlwaysRegistered(): void
    {
        $this->load([
            'tracing' => [
                'kernel' => false,
            ],
        ]);

        $this->assertContainerBuilderHasService(NoopTextMapPropagator::class);
        $this->assertContainerBuilderHasService(NoopTracerProvider::class);
        $this->assertContainerBuilderHasService(NoopMeterProvider::class);
    }

    public function testExtractRemoteContextTrueUsesDefaultTextMapPropagator(): void
    {
        $this->load();

        $this->assertContainerBuilderHasServiceDefinitionWithArgument(RequestListener::class, '$propagator', new Reference(TextMapPropagatorInterface::class));
    }

    public function testExtractRemoteContextFalseUsesNoopTextMapPropagator(): void
    {
        $this->load([
            'tracing' => [
                'kernel' => [
                    'extractRemoteContext' => false,
                ],
            ],
        ]);

        $this->assertContainerBuilderHasServiceDefinitionWithArgument(RequestListener::class, '$propagator', new Reference(NoopTextMapPropagator::class));
    }

    public function testRequestHeadersSetsHeadersToExtract(): void
    {
        $this->load([
            'tracing' => [
                'kernel' => [
                    'requestHeaders' => ['a', 'b'],
                ],
            ],
        ]);

        $this->assertContainerBuilderHasServiceDefinitionWithArgument(RequestListener::class, '$requestHeaders', ['a', 'b']);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument(RequestListener::class, '$responseHeaders', []);
    }

    public function testResponseHeadersSetsHeadersToExtract(): void
    {
        $this->load([
            'tracing' => [
                'kernel' => [
                    'responseHeaders' => ['a', 'b'],
                ],
            ],
        ]);

        $this->assertContainerBuilderHasServiceDefinitionWithArgument(RequestListener::class, '$requestHeaders', []);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument(RequestListener::class, '$responseHeaders', ['a', 'b']);
    }
}
