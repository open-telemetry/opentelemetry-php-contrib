<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\PDO;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;

class Opentelemetry
{
    public static function getTraceContextValues()
    {
        $carrier = [];

        $trace = TraceContextPropagator::getInstance();
        $trace->inject($carrier);

        return $carrier;
    }

    public static function getServiceNameValues()
    {
        $carrier = [];

        if (class_exists('OpenTelemetry\Contrib\Propagation\ServiceName\ServiceNamePropagator')) {
            /** @phan-suppress-next-line PhanUndeclaredClassMethod */
            $trace = new \OpenTelemetry\Contrib\Propagation\ServiceName\ServiceNamePropagator();
            /** @phan-suppress-next-line PhanAccessMethodInternal,PhanUndeclaredClassMethod */
            $trace->inject($carrier);
        }

        return $carrier;
    }
}
