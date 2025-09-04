<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\PDO;

use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\Context\Propagation\MultiTextMapPropagator;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Registry;

class ContextPropagatorFactory
{
    use LogsMessagesTrait;

    public function create(): TextMapPropagatorInterface | null
    {
        $propagators = [];
        if (class_exists('OpenTelemetry\SDK\Common\Configuration\Configuration')) {
            $propagators = Configuration::getList(Variables::OTEL_PHP_INSTRUMENTATION_PDO_CONTEXT_PROPAGATORS, []);
        }

        switch (count($propagators)) {
            case 0:
                return null;
            case 1:
                return $this->buildPropagator($propagators[0]);
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
            if ($propagator !== null) {
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
