<?php

use PHPUnit\Framework\TestCase;
use OpenTelemetry\Contrib\Instrumentation\PDO\Opentelemetry;

class OpentelemetryTest extends TestCase
{
    public function testGetTraceContextValuesReturnsArray()
    {
        $result = Opentelemetry::getTraceContextValues();
        $this->assertIsArray($result);
    }

    public function testGetServiceNameValuesReturnsArray()
    {
        $result = Opentelemetry::getServiceNameValues();
        $this->assertIsArray($result);
    }

    public function testGetServiceNameValuesHandlesMissingClass()
    {
        // Temporarily ensure the class does not exist
        if (class_exists('OpenTelemetry\Contrib\Propagation\ServiceName\ServiceNamePropagator')) {
            $this->markTestSkipped('ServiceNamePropagator class exists, cannot test missing class branch.');
        }
        $result = Opentelemetry::getServiceNameValues();
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
