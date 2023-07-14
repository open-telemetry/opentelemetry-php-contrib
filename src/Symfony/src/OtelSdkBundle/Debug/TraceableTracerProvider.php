<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Symfony\OtelSdkBundle\Debug;

use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Contrib\Symfony\OtelSdkBundle\DataCollector\OtelDataCollector;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

/** @phan-file-suppress PhanUndeclaredInterface */
/** @phan-file-suppress PhanUndeclaredTypeProperty */
/** @phan-file-suppress PhanUndeclaredTypeParameter */
/** @phan-file-suppress PhanUndeclaredClassMethod */
/** @phan-file-suppress PhanTypeMismatchArgument */
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

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return $this->tracerProvider->forceFlush();
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->tracerProvider->shutdown();
    }

    public function getTracer(string $name, ?string $version = null, ?string $schemaUrl = null, iterable $attributes = []): TracerInterface
    {
        return $this->tracerProvider->getTracer($name, $version, $schemaUrl, $attributes);
    }
}
