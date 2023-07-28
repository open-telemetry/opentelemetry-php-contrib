<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Symfony\Test\Unit\OtelBundle\DependencyInjection;

use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractExtensionTestCase;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Contrib\Symfony\OtelBundle\Console\ConsoleListener;
use OpenTelemetry\Contrib\Symfony\OtelBundle\DependencyInjection\OtelExtension;
use OpenTelemetry\Contrib\Symfony\OtelBundle\HttpKernel\RequestListener;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @covers \OpenTelemetry\Contrib\Symfony\OtelBundle\DependencyInjection\OtelExtension
 */
final class OtelExtensionTest extends AbstractExtensionTestCase
{
    protected function getContainerExtensions(): array
    {
        return [
            new OtelExtension(),
        ];
    }

    public function testEmptyConfigEnablesConsoleTracing(): void
    {
        $this->load();

        $this->assertContainerBuilderHasService(ConsoleListener::class);
    }

    public function testConsoleTracingCanBeDisabled(): void
    {
        $this->load([
            'tracing' => [
                'console' => false,
            ],
        ]);

        $this->assertContainerBuilderNotHasService(ConsoleListener::class);
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
                'http' => [
                    'server' => [
                        'requestHeaders' => ['a', 'b'],
                    ],
                ],
            ],
        ]);

        $this->assertContainerBuilderHasParameter('otel.tracing.http.server.request_headers', ['a', 'b']);
        $this->assertContainerBuilderHasParameter('otel.tracing.http.server.response_headers', []);
    }

    public function testResponseHeadersSetsHeadersToExtract(): void
    {
        $this->load([
            'tracing' => [
                'http' => [
                    'server' => [
                        'responseHeaders' => ['a', 'b'],
                    ],
                ],
            ],
        ]);

        $this->assertContainerBuilderHasParameter('otel.tracing.http.server.request_headers', []);
        $this->assertContainerBuilderHasParameter('otel.tracing.http.server.response_headers', ['a', 'b']);
    }
}
