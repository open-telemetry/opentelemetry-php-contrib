<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\Debug;

use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use OpenTelemetry\Symfony\OtelSdkBundle\DataCollector\OtelDataCollector;

class TraceableTracerProvider implements TracerProviderInterface
{
    private TracerProviderInterface $tracerProvider;
    private OtelDataCollector $dataCollector;

    public function __construct(TracerProviderInterface $tracerProvider, OtelDataCollector $dataCollector)
    {
        $this->tracerProvider = $tracerProvider;
        $this->dataCollector = $dataCollector;
        $this->dataCollector->setTracerProvider($tracerProvider);
    }

    public function forceFlush(): bool
    {
        return $this->tracerProvider->forceFlush();
    }

    public function shutdown(): bool
    {
        return $this->tracerProvider->shutdown();
    }

    public function getTracer(string $name, ?string $version = null, ?string $schemaUrl = null, iterable $attributes = []): TracerInterface
    {
        return $this->tracerProvider->getTracer($name, $version, $schemaUrl, $attributes);
    }
}
