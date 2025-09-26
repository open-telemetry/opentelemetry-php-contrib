<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\SqlCommenter;

use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\Context\Propagation\MultiTextMapPropagator;
use OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Registry;

class ContextPropagatorFactory
{
    use LogsMessagesTrait;

    public function create(): ?TextMapPropagatorInterface
    {
        $propagators = [];
        if (class_exists('OpenTelemetry\SDK\Common\Configuration\Configuration')) {
            $propagators = \OpenTelemetry\SDK\Common\Configuration\Configuration::getList('OTEL_PHP_SQLCOMMENTER_CONTEXT_PROPAGATORS', []);
        }

        switch (count($propagators)) {
            case 0:
                return null;
            case 1:
                $propagator = $this->buildPropagator($propagators[0]);
                if ($propagator !== null && is_a($propagator, NoopTextMapPropagator::class)) {
                    return null;
                }

                return $propagator;
            default:
                $props = $this->buildPropagators($propagators);
                if ($props) {
                    return new MultiTextMapPropagator($props);
                }

                return null;
        }
    }

    /**
     * @return ?list<TextMapPropagatorInterface>
     */
    private function buildPropagators(array $names): ?array
    {
        $propagators = [];
        foreach ($names as $name) {
            $propagator = $this->buildPropagator($name);
            if ($propagator !== null && !is_a($propagator, NoopTextMapPropagator::class)) {
                $propagators[] = $propagator;
            }
        }
        if (count($propagators) === 0) {
            return null;
        }

        return $propagators;
    }

    private function buildPropagator(string $name): ?TextMapPropagatorInterface
    {
        try {
            return Registry::textMapPropagator($name);
        } catch (\RuntimeException $e) {
            self::logWarning($e->getMessage());
        }

        return null;
    }
}
