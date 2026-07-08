<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\HttpConfig\Unit;

use OpenTelemetry\Contrib\Instrumentation\HttpConfig\HttpServerConfig;
use PHPUnit\Framework\TestCase;

final class HttpServerConfigTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = new HttpServerConfig();
        $this->assertFalse($config->captureClientPort);
        $this->assertFalse($config->captureUserAgentSyntheticType);
        $this->assertFalse($config->captureNetworkTransport);
        $this->assertFalse($config->captureNetworkLocalAddress);
        $this->assertFalse($config->captureNetworkLocalPort);
        $this->assertFalse($config->captureRequestBodySize);
        $this->assertFalse($config->captureRequestSize);
        $this->assertFalse($config->captureResponseBodySize);
        $this->assertFalse($config->captureResponseSize);
    }

    public function testCustomValues(): void
    {
        $config = new HttpServerConfig(
            captureClientPort: true,
            captureUserAgentSyntheticType: true,
            captureNetworkTransport: true,
            captureNetworkLocalAddress: true,
            captureNetworkLocalPort: true,
            captureRequestBodySize: true,
            captureRequestSize: true,
            captureResponseBodySize: true,
            captureResponseSize: true,
        );
        $this->assertTrue($config->captureClientPort);
        $this->assertTrue($config->captureUserAgentSyntheticType);
        $this->assertTrue($config->captureNetworkTransport);
        $this->assertTrue($config->captureNetworkLocalAddress);
        $this->assertTrue($config->captureNetworkLocalPort);
        $this->assertTrue($config->captureRequestBodySize);
        $this->assertTrue($config->captureRequestSize);
        $this->assertTrue($config->captureResponseBodySize);
        $this->assertTrue($config->captureResponseSize);
    }
}
