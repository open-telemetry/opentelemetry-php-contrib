<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\PDO\Opentelemetry;
use PHPUnit\Framework\TestCase;

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
