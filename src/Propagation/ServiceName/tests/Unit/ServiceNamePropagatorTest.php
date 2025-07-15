<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Propagation\ServiceName\Unit;

use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Propagation\ServiceName\ServiceNamePropagator;
use OpenTelemetry\SemConv\ResourceAttributes;
use Override;
use PHPUnit\Framework\TestCase;

final class ServiceNamePropagatorTest extends TestCase
{
    private ServiceNamePropagator $serviceNamePropagator;

    #[Override]
    protected function setUp(): void
    {
        $this->serviceNamePropagator = ServiceNamePropagator::getInstance();
    }

    public function test_fields(): void
    {
        $this->assertSame(
            [ResourceAttributes::SERVICE_NAME],
            $this->serviceNamePropagator->fields()
        );
    }

    public function test_inject_empty(): void
    {
        $carrier = [];
        $this->serviceNamePropagator->inject($carrier);
        $this->assertEmpty($carrier);
    }

    public function test_inject(): void
    {
        putenv('OTEL_SERVICE_NAME=foo-service');
        $carrier = [];
        $this->serviceNamePropagator->inject($carrier);
        $this->assertEquals($carrier, [ResourceAttributes::SERVICE_NAME=>'foo-service']);
        putenv('OTEL_SERVICE_NAME');
    }

    public function test_extract_empty(): void
    {
        $carrier = [];
        $context = $this->serviceNamePropagator->extract($carrier);
        $this->assertSame(Context::getCurrent(), $context);
    }

    public function test_no_extract(): void
    {
        $carrier = [ResourceAttributes::SERVICE_NAME => 'foo-service'];
        $context = $this->serviceNamePropagator->extract($carrier);
        $this->assertSame(Context::getCurrent(), $context);
    }
}
