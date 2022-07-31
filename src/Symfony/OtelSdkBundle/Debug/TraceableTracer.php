<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\Debug;

use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Trace\Tracer;
use OpenTelemetry\Symfony\OtelSdkBundle\DataCollector\OtelDataCollector;

class TraceableTracer implements TracerInterface
{
    private TracerInterface $tracer;
    private OtelDataCollector $dataCollector;

    public function __construct(TracerInterface $tracer, OtelDataCollector $dataCollector)
    {
        $this->tracer = $tracer;
        $this->dataCollector = $dataCollector;
        $this->dataCollector->setTracer($tracer);
    }

    /** @inheritDoc */
    public function spanBuilder(string $spanName): SpanBuilderInterface
    {
        if (ctype_space($spanName)) {
            $spanName = Tracer::FALLBACK_SPAN_NAME;
        }

        return $this->tracer->spanBuilder($spanName);
    }
}
