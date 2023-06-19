<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Symfony\Unit\OtelSdkBundle\Trace;

use OpenTelemetry\Contrib;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\Symfony\OtelSdkBundle\Trace\ExporterFactory;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use stdClass;

class ExporterFactoryTest extends TestCase
{
    use ProphecyTrait;

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

    public function testOptionMapping()
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

    public function testBuildNonExporterException()
    {
        $this->expectException(
            RuntimeException::class
        );

        (new ExporterFactory(stdClass::class))
            ->build();
    }

    public function testBuildZipkin()
    {
        $factory = new ExporterFactory(Contrib\Zipkin\Exporter::class);

        $exporter = $factory->build([
            'transport' => $this->prophesize(TransportInterface::class)->reveal(),
        ]);

        $this->assertInstanceOf(
            Contrib\Zipkin\Exporter::class,
            $exporter
        );
    }

    public function testBuildNewrelic()
    {
        $factory = new ExporterFactory(Contrib\Newrelic\Exporter::class);

        $exporter = $factory->build([
            'endpoint_url' => 'http://localhost:1234/path',
            'transport' => $this->prophesize(TransportInterface::class)->reveal(),
        ]);

        $this->assertInstanceOf(
            Contrib\Newrelic\Exporter::class,
            $exporter
        );
    }

    public function testBuildOtlpHttp()
    {
        $factory = new ExporterFactory(Contrib\Otlp\SpanExporter::class);
        $transport = $this->prophesize(TransportInterface::class);
        $transport->contentType()->willReturn('application/json');

        $exporter = $factory->build([
            'transport' => $transport->reveal(),
        ]);

        $this->assertInstanceOf(
            Contrib\Otlp\SpanExporter::class,
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

    public function export(iterable $spans, ?CancellationInterface $cancellation = null): FutureInterface
    {
        return new CompletedFuture(1);
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
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
