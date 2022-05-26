<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelInstrumentationBundle\DependencyInjection\Compiler;

use OpenTelemetry\SDK\Trace\Tracer;
use OpenTelemetry\Symfony\HttpClientInstrumentation\OtelTraceableHttpClient;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class HttpClientCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        if (!class_exists(OtelTraceableHttpClient::class)) {
            return;
        }

        foreach ($container->findTaggedServiceIds('http_client.client') as $id => $tags) {
            $container->register('.otel_instrumentation.' . $id, OtelTraceableHttpClient::class)
                ->setArguments([
                    new Reference('.otel_instrumentation.' . $id . '.inner'),
                    new Reference(Tracer::class),
                ])
                ->setDecoratedService($id);
        }
    }
}
