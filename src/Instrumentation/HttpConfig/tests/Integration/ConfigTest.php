<?php

declare(strict_types=1);

namespace OpenTelemetry\TestUtils\Instrumentation\HttpConfig\tests\Integration;

use League\Uri\Http;
use OpenTelemetry\API\Configuration\ConfigProperties;
use OpenTelemetry\Config\SDK\Instrumentation;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\HttpConfig;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer\DefaultSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{

    #[DataProvider('configRegistryProviderSanitizeFieldNames')]
    public function testSanitizeFieldNames(ConfigProperties $properties): void
    {
        $httpConfig = $properties->get(HttpConfig::class);
        $this->assertInstanceOf(HttpConfig::class, $httpConfig);

        $this->assertEquals(
            Http::new('https://example.com?key=value&passwd=REDACTED&secret=REDACTED'),
            $httpConfig->sanitizer->sanitize(Http::new('https://example.com?key=value&passwd=1234&secret=abc')),
        );
    }

    public static function configRegistryProviderSanitizeFieldNames(): iterable
    {
        yield 'config' => [Instrumentation::parseFile(__DIR__ . '/fixtures/sdk-config-redact-query-string-values.yaml')->create()];
    }

    #[DataProvider('configPropertiesProviderKnownHttpMethods')]
    public function testKnownHttpMethods(ConfigProperties $properties): void
    {
        $httpConfig = $properties->get(HttpConfig::class);
        $this->assertInstanceOf(HttpConfig::class, $httpConfig);

        $this->assertSame(
            ['CONNECT', 'DELETE', 'GET', 'HEAD', 'OPTIONS', 'PATCH', 'POST', 'PUT', 'TRACE', 'CUSTOM'],
            $httpConfig->knownHttpMethods,
        );
    }

    public static function configPropertiesProviderKnownHttpMethods(): iterable
    {
        yield 'config' => [Instrumentation::parseFile(__DIR__ . '/fixtures/sdk-config-known-http-methods.yaml')->create()];
    }

    #[DataProvider('configPropertiesProviderCaptureAttributes')]
    public function testCaptureAttributes(ConfigProperties $properties): void
    {
        $httpConfig = $properties->get(HttpConfig::class);
        $this->assertInstanceOf(HttpConfig::class, $httpConfig);

        $this->assertTrue($httpConfig->client->captureUrlScheme);
        $this->assertTrue($httpConfig->client->captureUrlTemplate);
        $this->assertTrue($httpConfig->client->captureUserAgentOriginal);
        $this->assertTrue($httpConfig->client->captureUserAgentSyntheticType);
        $this->assertTrue($httpConfig->client->captureNetworkTransport);
        $this->assertTrue($httpConfig->client->captureRequestBodySize);
        $this->assertTrue($httpConfig->client->captureRequestSize);
        $this->assertTrue($httpConfig->client->captureResponseBodySize);
        $this->assertTrue($httpConfig->client->captureResponseSize);

        $this->assertTrue($httpConfig->server->captureClientPort);
        $this->assertTrue($httpConfig->server->captureUserAgentSyntheticType);
        $this->assertTrue($httpConfig->server->captureNetworkTransport);
        $this->assertTrue($httpConfig->server->captureNetworkLocalAddress);
        $this->assertTrue($httpConfig->server->captureNetworkLocalPort);
        $this->assertTrue($httpConfig->server->captureRequestBodySize);
        $this->assertTrue($httpConfig->server->captureRequestSize);
        $this->assertTrue($httpConfig->server->captureResponseBodySize);
        $this->assertTrue($httpConfig->server->captureResponseSize);
    }

    public static function configPropertiesProviderCaptureAttributes(): iterable
    {
        yield 'config' => [Instrumentation::parseFile(__DIR__ . '/fixtures/sdk-config-capture-attributes.yaml')->create()];
    }

    #[DataProvider('configEmptyInstrumentationNodeProvider')]
    public function testEmptyInstrumentationNode(ConfigProperties $properties): void
    {
        $httpConfig = $properties->get(HttpConfig::class);
        $this->assertInstanceOf(HttpConfig::class, $httpConfig);

        $this->assertFalse($httpConfig->client->captureUrlScheme);
        $this->assertFalse($httpConfig->client->captureUrlTemplate);
        $this->assertFalse($httpConfig->client->captureUserAgentOriginal);
        $this->assertFalse($httpConfig->client->captureUserAgentSyntheticType);
        $this->assertFalse($httpConfig->client->captureNetworkTransport);
        $this->assertFalse($httpConfig->client->captureRequestBodySize);
        $this->assertFalse($httpConfig->client->captureRequestSize);
        $this->assertFalse($httpConfig->client->captureResponseBodySize);
        $this->assertFalse($httpConfig->client->captureResponseSize);

        $this->assertFalse($httpConfig->server->captureClientPort);
        $this->assertFalse($httpConfig->server->captureUserAgentSyntheticType);
        $this->assertFalse($httpConfig->server->captureNetworkTransport);
        $this->assertFalse($httpConfig->server->captureNetworkLocalAddress);
        $this->assertFalse($httpConfig->server->captureNetworkLocalPort);
        $this->assertFalse($httpConfig->server->captureRequestBodySize);
        $this->assertFalse($httpConfig->server->captureRequestSize);
        $this->assertFalse($httpConfig->server->captureResponseBodySize);
        $this->assertFalse($httpConfig->server->captureResponseSize);

        $this->assertSame(
            ['CONNECT', 'DELETE', 'GET', 'HEAD', 'OPTIONS', 'PATCH', 'POST', 'PUT', 'TRACE'],
            $httpConfig->knownHttpMethods,
        );
        $this->assertEquals(new DefaultSanitizer(), $httpConfig->sanitizer);
    }

    public static function configEmptyInstrumentationNodeProvider(): iterable
    {
        yield 'config' => [Instrumentation::parseFile(__DIR__ . '/fixtures/sdk-config-empty-instrumentation-node.yaml')->create()];
    }
}
