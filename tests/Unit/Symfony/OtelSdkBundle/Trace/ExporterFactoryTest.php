<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\Trace;

use OpenTelemetry\Contrib;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\Symfony\OtelSdkBundle\Trace\ExporterFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use stdClass;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\Trace\ExporterFactory
 */
class ExporterFactoryTest extends TestCase
{
    public function testBuildAllOptions(): void
    {
        $factory = new ExporterFactory(TestExporter::class);

        $exporter = $factory->build([
            'name' => 'foo',
            'endpoint_url' => 'http://localhost:1234/path',
            'client' => $this->createClientInterfaceMock(),
            'request_factory' => $this->createRequestFactoryInterfaceMock(),
            'stream_factory' => $this->createStreamFactoryInterfaceMock(),
        ]);

        $this->assertInstanceOf(
            TestExporter::class,
            $exporter
        );
    }

    public function testBuildResolvedHttpFactories(): void
    {
        $factory = new ExporterFactory(TestExporter::class);

        $exporter = $factory->build([
            'name' => 'foo',
            'endpoint_url' => 'http://localhost:1234/path',
        ]);

        $this->assertInstanceOf(
            TestExporter::class,
            $exporter
        );
    }

    public function testInvoke(): void
    {
        $factory = new ExporterFactory(TestExporter::class);

        $exporter = $factory([
            'name' => 'foo',
            'endpoint_url' => 'http://localhost:1234/path',
        ]);

        $this->assertInstanceOf(
            TestExporter::class,
            $exporter
        );
    }

    public function testOptionMapping(): void
    {
        $factory = new ExporterFactory(TestExporter::class);

        $exporter = $factory([
            'service_name' => 'foo',
            'url' => 'http://localhost:1234/path',
        ]);

        $this->assertInstanceOf(
            TestExporter::class,
            $exporter
        );
    }

    public function testBuildNonExporterException(): void
    {
        $this->expectException(
            RuntimeException::class
        );

        (new ExporterFactory(stdClass::class))
            ->build();
    }

    public function testBuildZipkin(): void
    {
        $factory = new ExporterFactory(Contrib\Zipkin\Exporter::class);

        $exporter = $factory->build([
            'name' => 'foo',
            'endpoint_url' => 'http://localhost:1234/path',
        ]);

        $this->assertInstanceOf(
            Contrib\Zipkin\Exporter::class,
            $exporter
        );
    }

    public function testBuildJaeger(): void
    {
        $factory = new ExporterFactory(Contrib\Jaeger\Exporter::class);

        $exporter = $factory->build([
            'name' => 'foo',
            'endpoint_url' => 'http://localhost:1234/path',
        ]);

        $this->assertInstanceOf(
            Contrib\Jaeger\Exporter::class,
            $exporter
        );
    }

    public function testBuildNewrelic(): void
    {
        $factory = new ExporterFactory(Contrib\Newrelic\Exporter::class);

        $exporter = $factory->build([
            'name' => 'foo',
            'endpoint_url' => 'http://localhost:1234/path',
            'license_key' => 'gadouzdSD',
        ]);

        $this->assertInstanceOf(
            Contrib\Newrelic\Exporter::class,
            $exporter
        );
    }

    /**
     * @requires extension grpc
     */
    public function testBuildOtlpGrpc(): void
    {
        $factory = new ExporterFactory(Contrib\OtlpGrpc\Exporter::class);

        $exporter = $factory->build([
            'endpoint_url' => 'http://localhost:1234/path',
        ]);

        $this->assertInstanceOf(
            Contrib\OtlpGrpc\Exporter::class,
            $exporter
        );
    }

    public function testBuildOtlpHttp(): void
    {
        $factory = new ExporterFactory(Contrib\OtlpHttp\Exporter::class);

        $exporter = $factory->build([ ]);

        $this->assertInstanceOf(
            Contrib\OtlpHttp\Exporter::class,
            $exporter
        );
    }

    public function testBuildZipkinToNewrelic(): void
    {
        $factory = new ExporterFactory(Contrib\ZipkinToNewrelic\Exporter::class);

        $exporter = $factory->build([
            'name' => 'foo',
            'endpoint_url' => 'http://localhost:1234/path',
            'license_key' => 'gadouzdSD',
        ]);

        $this->assertInstanceOf(
            Contrib\ZipkinToNewrelic\Exporter::class,
            $exporter
        );
    }

    private function createClientInterfaceMock(): ClientInterface
    {
        return $this->createMock(ClientInterface::class);
    }

    private function createRequestFactoryInterfaceMock(): RequestFactoryInterface
    {
        return $this->createMock(RequestFactoryInterface::class);
    }

    private function createStreamFactoryInterfaceMock(): StreamFactoryInterface
    {
        return $this->createMock(StreamFactoryInterface::class);
    }
}

class TestExporter implements SpanExporterInterface
{
    private string $name;

    private string $endpointUrl;

    private ClientInterface $client;

    private RequestFactoryInterface $requestFactory;

    private StreamFactoryInterface $streamFactory;

    public function __construct(
        string $name,
        string $endpointUrl,
        ClientInterface $client,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory
    ) {
        $this->name = $name;
        $this->endpointUrl = $endpointUrl;
        $this->client = $client;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
    }

    public static function fromConnectionString(string $endpointUrl, string $name, string $args)
    {
    }

    public function export(iterable $spans): int
    {
        return 1;
    }

    public function shutdown(): bool
    {
        return true;
    }

    public function forceFlush(): bool
    {
        return true;
    }

    /**
     * @return string
     */
    public function getEndpointUrl(): string
    {
        return $this->endpointUrl;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return ClientInterface
     */
    public function getClient(): ClientInterface
    {
        return $this->client;
    }

    /**
     * @return RequestFactoryInterface
     */
    public function getRequestFactory(): RequestFactoryInterface
    {
        return $this->requestFactory;
    }

    /**
     * @return StreamFactoryInterface
     */
    public function getStreamFactory(): StreamFactoryInterface
    {
        return $this->streamFactory;
    }
}
