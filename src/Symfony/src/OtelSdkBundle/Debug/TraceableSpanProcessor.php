<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Symfony\OtelSdkBundle\Debug;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Contrib\Symfony\OtelSdkBundle\DataCollector\OtelDataCollector;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;

/** @phan-file-suppress PhanUndeclaredInterface */
/** @phan-file-suppress PhanUndeclaredTypeProperty */
/** @phan-file-suppress PhanUndeclaredTypeParameter */
/** @phan-file-suppress PhanUndeclaredClassMethod */
class TraceableSpanProcessor implements SpanProcessorInterface
{
    private SpanProcessorInterface $spanProcessor;
    private OtelDataCollector $dataCollector;

    public function __construct(SpanProcessorInterface $spanProcessor, OtelDataCollector $dataCollector)
    {
        $this->spanProcessor = $spanProcessor;
        $this->dataCollector = $dataCollector;

        $reflectedSpanProcessor = new \ReflectionClass($this->spanProcessor);
        if (true === $reflectedSpanProcessor->hasProperty('exporter')) {
            $exporter = $reflectedSpanProcessor->getProperty('exporter');
            $exporter->setAccessible(true);
            $this->dataCollector->setExporterData($exporter->getValue($this->spanProcessor));
        }
    }

    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
    {
        $this->dataCollector->collectedSpans[$span->getContext()->getSpanId()] = $span;
        $this->spanProcessor->onStart($span, $parentContext);
    }

    public function onEnd(ReadableSpanInterface $span): void
    {
        $this->spanProcessor->onEnd($span);
        $this->dataCollector->collectedSpans[$span->getContext()->getSpanId()] = $span;
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return $this->spanProcessor->forceFlush();
    }

    public function shutdown(CancellationInterface $cancellation = null): bool
    {
        return $this->spanProcessor->shutdown();
    }
}
