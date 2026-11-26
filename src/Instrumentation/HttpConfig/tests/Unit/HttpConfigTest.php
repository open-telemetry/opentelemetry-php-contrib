<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\HttpConfig\Unit;

use OpenTelemetry\Contrib\Instrumentation\HttpConfig\HttpClientConfig;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\HttpConfig;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\HttpServerConfig;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer\DefaultSanitizer;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer\NoopSanitizer;
use PHPUnit\Framework\TestCase;

final class HttpConfigTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = new HttpConfig();
        $this->assertInstanceOf(HttpClientConfig::class, $config->client);
        $this->assertInstanceOf(HttpServerConfig::class, $config->server);
        $this->assertInstanceOf(DefaultSanitizer::class, $config->sanitizer);
        $this->assertSame(HttpConfig::HTTP_METHODS, $config->knownHttpMethods);
    }

    public function testCustomValues(): void
    {
        $client = new HttpClientConfig(captureUrlScheme: true);
        $server = new HttpServerConfig(captureClientPort: true);
        $sanitizer = new NoopSanitizer();
        $methods = ['GET', 'POST'];

        $config = new HttpConfig(
            client: $client,
            server: $server,
            sanitizer: $sanitizer,
            knownHttpMethods: $methods,
        );

        $this->assertSame($client, $config->client);
        $this->assertSame($server, $config->server);
        $this->assertSame($sanitizer, $config->sanitizer);
        $this->assertSame($methods, $config->knownHttpMethods);
    }

    public function testHttpMethodsConstant(): void
    {
        $this->assertSame(
            ['CONNECT', 'DELETE', 'GET', 'HEAD', 'OPTIONS', 'PATCH', 'POST', 'PUT', 'TRACE'],
            HttpConfig::HTTP_METHODS
        );
    }
}
