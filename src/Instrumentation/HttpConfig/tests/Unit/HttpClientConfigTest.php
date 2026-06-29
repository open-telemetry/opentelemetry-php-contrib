<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\HttpConfig\Unit;

use OpenTelemetry\Contrib\Instrumentation\HttpConfig\HttpClientConfig;
use PHPUnit\Framework\TestCase;

final class HttpClientConfigTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = new HttpClientConfig();
        $this->assertFalse($config->captureUrlScheme);
        $this->assertFalse($config->captureUrlTemplate);
        $this->assertFalse($config->captureUserAgentOriginal);
        $this->assertFalse($config->captureUserAgentSyntheticType);
        $this->assertFalse($config->captureNetworkTransport);
        $this->assertFalse($config->captureRequestBodySize);
        $this->assertFalse($config->captureRequestSize);
        $this->assertFalse($config->captureResponseBodySize);
        $this->assertFalse($config->captureResponseSize);
    }

    public function testCustomValues(): void
    {
        $config = new HttpClientConfig(
            captureUrlScheme: true,
            captureUrlTemplate: true,
            captureUserAgentOriginal: true,
            captureUserAgentSyntheticType: true,
            captureNetworkTransport: true,
            captureRequestBodySize: true,
            captureRequestSize: true,
            captureResponseBodySize: true,
            captureResponseSize: true,
        );
        $this->assertTrue($config->captureUrlScheme);
        $this->assertTrue($config->captureUrlTemplate);
        $this->assertTrue($config->captureUserAgentOriginal);
        $this->assertTrue($config->captureUserAgentSyntheticType);
        $this->assertTrue($config->captureNetworkTransport);
        $this->assertTrue($config->captureRequestBodySize);
        $this->assertTrue($config->captureRequestSize);
        $this->assertTrue($config->captureResponseBodySize);
        $this->assertTrue($config->captureResponseSize);
    }
}
