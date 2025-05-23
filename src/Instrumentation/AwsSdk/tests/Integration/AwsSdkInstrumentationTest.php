<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\AwsSdk\Integration;

use ArrayObject;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\Contrib\Instrumentation\AwsSdk\AwsSdkInstrumentation
 */
/** @psalm-suppress TooManyArguments */
class AwsSdkInstrumentationTest extends TestCase
{
    private S3Client $client;
    private MockHandler $mock;
    private ArrayObject $spans;
    private ScopeInterface $scope;

    public function setUp(): void
    {
        $this->spans = new ArrayObject();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(new InMemoryExporter($this->spans))
        );
        $this->scope = Configurator::create()
            ->withTracerProvider($tracerProvider)
            ->withPropagator(TraceContextPropagator::getInstance())
            ->activate();

        $this->mock = new MockHandler();
        $this->mock->append(new Result([
            '@metadata' => [
                'statusCode' => 200,
                'headers' => [
                    'x-amz-request-id' => 'TEST-REQUEST-ID',
                ],
            ],
        ]));

        $this->client = new S3Client([
            'region'   => 'us-west-2',
            'version'  => 'latest',
            'handler'  => $this->mock,
            'credentials' => false,
        ]);
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    public function test_listBuckets_generates_one_aws_span_with_expected_attributes(): void
    {
        $this->client->listBuckets();

        $this->assertCount(1, $this->spans);

        $span = $this->spans->offsetGet(0);

        $this->assertInstanceOf(ImmutableSpan::class, $span);

        $this->assertSame('s3.ListBuckets', $span->getName());

        $attrs = $span->getAttributes();
        $this->assertSame('Aws\AwsClient::execute', $attrs->get('code.function.name'));
        $this->assertSame('aws-api', $attrs->get('rpc.system'));
        $this->assertSame('s3', $attrs->get('rpc.service'));
        $this->assertSame('ListBuckets', $attrs->get('rpc.method'));
        $this->assertSame('us-west-2', $attrs->get('cloud.region'));
        $this->assertSame(200, $attrs->get('http.response.status_code'));
        $this->assertSame('TEST-REQUEST-ID', $attrs->get('aws.request_id'));
    }
}
