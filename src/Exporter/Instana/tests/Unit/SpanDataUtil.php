<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Exporter\Instana\Unit;

/**
 * This is an modified copy of the following
 * https://github.com/open-telemetry/opentelemetry-php/blob/0ae2723/tests/Unit/SDK/Util/SpanData.php
 */

use function count;
use function max;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace as API;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Attribute\AttributesBuilderInterface;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScope;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace as SDK;
use OpenTelemetry\SDK\Trace\EventInterface;
use OpenTelemetry\SDK\Trace\LinkInterface;
use OpenTelemetry\SDK\Trace\StatusData;

class SpanDataUtil implements SDK\SpanDataInterface
{
    /** @var non-empty-string */
    private string $name = 'test-span-data';

    /** @var list<EventInterface> */
    private array $events = [];

    /** @var list<LinkInterface>
     */
    private array $links = [];

    private AttributesBuilderInterface $attributesBuilder;
    private int $kind = API\SpanKind::KIND_INTERNAL;
    private StatusData $status;
    private ResourceInfo $resource;
    private InstrumentationScope $instrumentationScope;
    private API\SpanContextInterface $context;
    private API\SpanContextInterface $parentContext;
    private int $totalRecordedEvents = 0;
    private int $totalRecordedLinks = 0;
    private int $startEpochNanos = 1505855794194009601;
    private int $endEpochNanos = 1505855799465726528;
    private bool $hasEnded = false;

    public function __construct()
    {
        $this->attributesBuilder = Attributes::factory()->builder();
        $this->status = StatusData::unset();
        $this->resource = ResourceInfoFactory::emptyResource();
        $this->instrumentationScope = new InstrumentationScope('', null, null, Attributes::create([]));
        $this->context = API\SpanContext::getInvalid();
        $this->parentContext = API\SpanContext::getInvalid();
    }

    #[\Override]
    public function getName(): string
    {
        return $this->name;
    }

    /** @param non-empty-string $name */
    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /** @inheritDoc */
    #[\Override]
    public function getLinks(): array
    {
        return $this->links;
    }

    /** @inheritDoc */
    #[\Override]
    public function getEvents(): array
    {
        return $this->events;
    }

    public function addEvent(string $name, AttributesInterface $attributes, ?int $timestamp = null): self
    {
        $this->events[] = new SDK\Event($name, $timestamp ?? Clock::getDefault()->now(), $attributes);

        return $this;
    }

    #[\Override]
    public function getAttributes(): AttributesInterface
    {
        return $this->attributesBuilder->build();
    }

    public function addAttribute(string $key, $value): self
    {
        $this->attributesBuilder[$key] = $value;

        return $this;
    }

    #[\Override]
    public function getTotalDroppedEvents(): int
    {
        return max(0, $this->totalRecordedEvents - count($this->events));
    }

    #[\Override]
    public function getTotalDroppedLinks(): int
    {
        return max(0, $this->totalRecordedLinks - count($this->links));
    }

    #[\Override]
    public function getKind(): int
    {
        return $this->kind;
    }

    public function setKind(int $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    #[\Override]
    public function getStatus(): StatusData
    {
        return $this->status;
    }

    #[\Override]
    public function getEndEpochNanos(): int
    {
        return $this->endEpochNanos;
    }

    #[\Override]
    public function getStartEpochNanos(): int
    {
        return $this->startEpochNanos;
    }

    #[\Override]
    public function hasEnded(): bool
    {
        return $this->hasEnded;
    }

    #[\Override]
    public function getResource(): ResourceInfo
    {
        return $this->resource;
    }

    #[\Override]
    public function getInstrumentationScope(): InstrumentationScope
    {
        return $this->instrumentationScope;
    }

    #[\Override]
    public function getContext(): API\SpanContextInterface
    {
        return $this->context;
    }

    public function setContext(API\SpanContextInterface $context): self
    {
        $this->context = $context;

        return $this;
    }

    #[\Override]
    public function getParentContext(): API\SpanContextInterface
    {
        return $this->parentContext;
    }

    public function setParentContext(API\SpanContextInterface $parentContext): self
    {
        $this->parentContext = $parentContext;

        return $this;
    }

    #[\Override]
    public function getTraceId(): string
    {
        return $this->getContext()->getTraceId();
    }

    #[\Override]
    public function getSpanId(): string
    {
        return $this->getContext()->getSpanId();
    }

    #[\Override]
    public function getParentSpanId(): string
    {
        return $this->getParentContext()->getSpanId();
    }
}
