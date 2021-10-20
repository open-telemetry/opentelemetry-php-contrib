<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\Trace;

use OpenTelemetry\Instrumentation\Symfony\OtelSdkBundle\Trace\ExporterFactory;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use stdClass;

class ExporterFactoryTest extends TestCase
{
    public function testBuildAllOptions()
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

    public function testBuildResolvedHttpFactories()
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

    public function testInvoke()
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

    public function testBuildNonExporterException()
    {
        $this->expectException(
            RuntimeException::class
        );

        (new ExporterFactory(stdClass::class))
            ->build();
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

    private $untyped;

    public function __construct(
        string $name,
        string $endpointUrl,
        ClientInterface $client,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        $untyped = 'untyped'
    ) {
        $this->name = $name;
        $this->endpointUrl = $endpointUrl;
        $this->client = $client;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
        $this->untyped = $untyped;
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
