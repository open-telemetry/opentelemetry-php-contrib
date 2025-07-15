<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Propagation\ServiceName;

use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Resource\Detectors;
use OpenTelemetry\SemConv\ResourceAttributes;

/**
 * Provides a TextMapPropagator implementation for service.name
 *
 * @see https://opentelemetry.io/docs/specs/semconv/resource/#service
 */
final class ServiceNamePropagator implements TextMapPropagatorInterface
{
    private const FIELDS = [
        ResourceAttributes::SERVICE_NAME,
    ];

    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @suppress PhanUndeclaredClassAttribute
     */
    #[\Override]
    public function fields(): array
    {
        return self::FIELDS;
    }

    /**
     * @suppress PhanUndeclaredClassAttribute
     */
    #[\Override]
    public function inject(&$carrier, ?PropagationSetterInterface $setter = null, ?ContextInterface $context = null): void
    {
        $setter ??= ArrayAccessGetterSetter::getInstance();

        $detector = new Detectors\Service();

        $resource = $detector->getResource();

        if ($resource->getAttributes()->has(ResourceAttributes::SERVICE_NAME)) {
            $setter->set($carrier, ResourceAttributes::SERVICE_NAME, $resource->getAttributes()->get(ResourceAttributes::SERVICE_NAME));
        }
    }

    /**
     * @suppress PhanUndeclaredClassAttribute
     */
    #[\Override]
    public function extract($carrier, ?PropagationGetterInterface $getter = null, ?ContextInterface $context = null): ContextInterface
    {
        $context ??= Context::getCurrent();

        return $context;
    }
}
