<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\Debug;

use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\Symfony\OtelSdkBundle\DataCollector\OtelDataCollector;

class TraceableSpanProcessor implements SpanProcessorInterface
{
    private SpanProcessorInterface $spanProcessor;
    private OtelDataCollector $dataCollector;

    public function __construct(SpanProcessorInterface $spanProcessor, OtelDataCollector $dataCollector)
    {
        $this->spanProcessor = $spanProcessor;
        $this->dataCollector = $dataCollector;

        $reflectedTracer = new \ReflectionClass($this->spanProcessor);
        $exporter = $reflectedTracer->getProperty('exporter');
        $exporter->setAccessible(true);
        $this->dataCollector->setExporterData($exporter->getValue($this->spanProcessor));
    }

    public function onStart(ReadWriteSpanInterface $span, ?Context $parentContext = null): void
    {
        $this->dataCollector->collectedSpans[$span->getContext()->getSpanId()] = $span;
        $this->spanProcessor->onStart($span, $parentContext);
    }

    public function onEnd(ReadableSpanInterface $span): void
    {
        $this->spanProcessor->onEnd($span);
        $this->dataCollector->collectedSpans[$span->getContext()->getSpanId()] = $span;
    }

    public function forceFlush(): bool
    {
        return $this->spanProcessor->forceFlush();
    }

    public function shutdown(): bool
    {
        return $this->spanProcessor->shutdown();
    }
}
