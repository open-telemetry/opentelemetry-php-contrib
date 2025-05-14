<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Propagation\Instana;

use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

class InstanaPropagator implements TextMapPropagatorInterface
{
    /** @var TextMapPropagatorInterface[] */
    private array $propagators;

    private static ?self $instance = null;

    public function __construct(array $propagators)
    {
        $this->propagators = $propagators;
    }

    public static function getInstance(): self
    {
        if (null === self::$instance) {
            // Create propagator instances
            $instanaPropagator =  InstanaContextPropagator::getInstance();
            $baggagePropagator = new BaggagePropagator();
            self::$instance = new self([$instanaPropagator, $baggagePropagator]);
        }

        return self::$instance;
    }
    
    /**
    * @suppress PhanUndeclaredClassAttribute
    */
    #[\Override]
    public function fields(): array
    {
        $fields = [];
        foreach ($this->propagators as $propagator) {
            $fields = array_merge($fields, $propagator->fields());
        }

        return array_values(array_unique($fields));
    }

    /**
     * @suppress PhanUndeclaredClassAttribute
     */
    #[\Override]
    public function inject(&$carrier, ?PropagationSetterInterface $setter = null, ?ContextInterface $context = null): void
    {
        foreach ($this->propagators as $propagator) {
            $propagator->inject($carrier, $setter, $context);
        }
    }
    
    /**
     * @suppress PhanUndeclaredClassAttribute
     */
    #[\Override]
    public function extract($carrier, ?PropagationGetterInterface $getter = null, ?ContextInterface $context = null): ContextInterface
    {
        
        foreach ($this->propagators as $propagator) {
            $context = $propagator->extract($carrier, $getter, $context);
        }
        
        return $context;
    }
}
