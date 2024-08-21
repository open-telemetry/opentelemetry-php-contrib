<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Symfony\OtelSdkBundle\DataCollector;

use OpenTelemetry\API\Instrumentation\InstrumentationInterface;
use OpenTelemetry\API\Instrumentation\InstrumentationTrait;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;
use Throwable;

class OtelDataCollector extends DataCollector implements LateDataCollectorInterface, InstrumentationInterface
{
    use InstrumentationTrait;

    public array $collectedSpans = [];

    public function reset(): void
    {
        $this->data = [];
    }

    /**
     * {@inheritDoc}
     */
    public function collect(Request $request, Response $response, ?Throwable $exception = null): void
    {
        // Everything is collected during the request, and formatted on kernel terminate.
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'otel';
    }

    /**
     * @return array|\Symfony\Component\VarDumper\Cloner\Data
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * {@inheritDoc}
     */
    public function lateCollect(): void
    {
        $this->loadDataFromTracerSharedState();
        $this->data['spans'] = $this->orderedSpans();
    }

    /**
     * @param class-string $class
     */
    public function getClassLocation(string $class): array
    {
        $reflection = new \ReflectionClass($class);

        return [
            'class' => $reflection->getShortName(),
            'file' => $reflection->getFileName(),
        ];
    }
    /**
     * @phan-suppress hanUndeclaredTypeParameter
     * @phan-suppress PhanUndeclaredTypeParameter
     */
    public function setExporterData(SpanExporterInterface $exporter): void
    {
        $this->data['exporter'] = $this->getClassLocation(get_class($exporter));
        //Add directory to exporter class because all exporter are named "Exporter"
        $this->data['exporter']['class'] = str_replace('.php', '', implode('/', array_slice(explode('/', $this->data['exporter']['file']), -2, 2, true)));
    }

    private function loadDataFromTracerSharedState(): void
    {
        $objectWithSharedState = $this->getTracerProvider();
        $reflectedTracer = new \ReflectionClass($objectWithSharedState);
        if (false === $reflectedTracer->hasProperty('tracerSharedState')) {
            return;
        }

        $tss = $reflectedTracer->getProperty('tracerSharedState');
        $tss->setAccessible(true);
        $this->data['id_generator'] = $this->getClassLocation(get_class($tss->getValue($objectWithSharedState)->getIdGenerator()));
        $this->data['sampler'] = $this->getClassLocation(get_class($tss->getValue($objectWithSharedState)->getSampler()));
        $this->data['span_processor'] = $this->getClassLocation(get_class($tss->getValue($objectWithSharedState)->getSpanProcessor()));
        $this->data['resource_info_attributes'] = $this->cloneVar($tss->getValue($objectWithSharedState)->getResource()->getAttributes());
        $this->data['span_limits'] = $this->cloneVar($tss->getValue($objectWithSharedState)->getSpanLimits());
    }

    private function orderedSpans(): array
    {
        $spanData = [];
        $rootSpanId = null;
        /** @var \OpenTelemetry\SDK\Trace\Span $span */
        foreach ($this->collectedSpans as $span) {
            //probably find better way to identify root span
            if (false === $span->getParentContext()->isValid()) {
                $spanData['root']['data'] = $this->spanDataToArray($span->toSpanData());
                $spanData['root']['children'] = [];
                $rootSpanId = $span->getContext()->getSpanId();
            }

            if ($rootSpanId === $span->getParentContext()->getSpanId()) {
                $spanData['root']['children'][] = $this->spanDataToArray($span->toSpanData());
            }
        }

        return $spanData;
    }
    
    /**
     * @phan-suppress PhanUndeclaredTypeParameter
     * @phan-suppress PhanUndeclaredClassMethod
     */
    private function spanDataToArray(SpanDataInterface $spanData): array
    {
        return [
            'spanId' => $spanData->getSpanId(),
            'traceId' => $spanData->getTraceId(),
            'name' => $spanData->getName(),
            'kind' => $spanData->getKind(),
            'attributes' => $spanData->getAttributes()->toArray(),
            'status' => $spanData->getStatus()->getCode(),
            'parentSpanId' => $spanData->getParentSpanId(),
            'links' => $spanData->getLinks(),
            'events' => $spanData->getEvents(),
        ];
    }

    public function getVersion() : ?string
    {
        return null;
    }

    public function getSchemaUrl() : ?string
    {
        return null;
    }

    public function init() : bool
    {
        return true;
    }
}
