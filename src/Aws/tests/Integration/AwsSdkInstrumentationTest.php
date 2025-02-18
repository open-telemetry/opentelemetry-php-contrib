<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Aws\Integration;

use Aws\AwsClientInterface;
use Aws\EventBridge\EventBridgeClient;
use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;
use OpenTelemetry\Aws\AwsSdkInstrumentation;
use OpenTelemetry\Aws\Xray\Propagator;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

class AwsSdkInstrumentationTest extends TestCase
{
    use UsesServiceTrait;

    private const HANDLERS_PER_ACTIVATION = 2; // one init and one sign middleware

    private AwsSdkInstrumentation $awsSdkInstrumentation;

    protected function setUp(): void
    {
        $this->awsSdkInstrumentation = new AwsSdkInstrumentation();
    }

    public function testProperClientNameAndRegionIsPassedToSpanForSingleClientCall()
    {
        /** @var SqsClient $sqsClient */
        $sqsClient = $this->getTestClient('SQS', ['region' => 'eu-west-1']);
        /** @var S3Client $s3Client */
        $s3Client = $this->getTestClient('S3', ['region' => 'us-east-1']);
        $this->addMockResults($s3Client, [[]]);
        /** @var EventBridgeClient $eventBridgeClient */
        $eventBridgeClient = $this->getTestClient('EventBridge', ['region' => 'ap-southeast-2']);

        $spanProcessor = new CollectingSpanProcessor();
        $this->awsSdkInstrumentation->instrumentClients([$sqsClient, $s3Client, $eventBridgeClient]);
        $this->awsSdkInstrumentation->setPropagator(new Propagator());
        $this->awsSdkInstrumentation->setTracerProvider(new TracerProvider([$spanProcessor]));
        $this->awsSdkInstrumentation->init();
        $this->awsSdkInstrumentation->activate();

        $s3Client->listBuckets();

        $collectedSpans = $spanProcessor->getCollectedSpans();
        $this->assertCount(1, $collectedSpans);

        /** @var ReadWriteSpanInterface $span */
        $span = reset($collectedSpans);
        $this->assertTrue($span->hasEnded());

        $attributes = $span->toSpanData()->getAttributes()->toArray();
        $this->assertArrayHasKey('rpc.service', $attributes);
        $this->assertSame('s3', $attributes['rpc.service']);
        $this->assertArrayHasKey('aws.region', $attributes);
        $this->assertSame('us-east-1', $attributes['aws.region']);
    }

    public function testProperClientNameAndRegionIsPassedToSpanForDoubleCallToSameClient()
    {
        /** @var SqsClient $sqsClient */
        $sqsClient = $this->getTestClient('SQS', ['region' => 'eu-west-1']);
        /** @var S3Client $s3Client */
        $s3Client = $this->getTestClient('S3', ['region' => 'us-east-1']);
        $this->addMockResults($s3Client, [[], []]);
        /** @var EventBridgeClient $eventBridgeClient */
        $eventBridgeClient = $this->getTestClient('EventBridge', ['region' => 'ap-southeast-2']);

        $spanProcessor = new CollectingSpanProcessor();
        $this->awsSdkInstrumentation->instrumentClients([$sqsClient, $s3Client, $eventBridgeClient]);
        $this->awsSdkInstrumentation->setPropagator(new Propagator());
        $this->awsSdkInstrumentation->setTracerProvider(new TracerProvider([$spanProcessor]));
        $this->awsSdkInstrumentation->init();
        $this->awsSdkInstrumentation->activate();

        $s3Client->listBuckets();
        $s3Client->listObjects(['Bucket' => 'foo']);

        $collectedSpans = $spanProcessor->getCollectedSpans();
        $this->assertCount(2, $collectedSpans);

        /** @var ReadWriteSpanInterface $span */
        foreach ($collectedSpans as $span) {
            $this->assertTrue($span->hasEnded());
            $attributes = $span->toSpanData()->getAttributes()->toArray();
            $this->assertArrayHasKey('rpc.service', $attributes);
            $this->assertSame('s3', $attributes['rpc.service']);
            $this->assertArrayHasKey('aws.region', $attributes);
            $this->assertSame('us-east-1', $attributes['aws.region']);
        }
    }

    public function testProperClientNameAndRegionIsPassedToSpanForDoubleCallToDifferentClients()
    {
        /** @var SqsClient $sqsClient */
        $sqsClient = $this->getTestClient('SQS', ['region' => 'eu-west-1']);
        /** @var S3Client $s3Client */
        $s3Client = $this->getTestClient('S3', ['region' => 'us-east-1']);
        $this->addMockResults($s3Client, [[]]);
        /** @var EventBridgeClient $eventBridgeClient */
        $eventBridgeClient = $this->getTestClient('EventBridge', ['region' => 'ap-southeast-2']);
        $this->addMockResults($eventBridgeClient, [[]]);

        $spanProcessor = new CollectingSpanProcessor();
        $this->awsSdkInstrumentation->instrumentClients([$sqsClient, $s3Client, $eventBridgeClient]);
        $this->awsSdkInstrumentation->setPropagator(new Propagator());
        $this->awsSdkInstrumentation->setTracerProvider(new TracerProvider([$spanProcessor]));
        $this->awsSdkInstrumentation->init();
        $this->awsSdkInstrumentation->activate();

        $eventBridgeClient->putEvents([
            'Entries' => [
                [
                    'Version' => 1,
                    'EventBusName' => 'foo',
                    'Source' => 'bar',
                    'DetailType' => 'type',
                    'Detail' => '{}',
                ],
            ],
        ]);
        $s3Client->listBuckets();

        $collectedSpans = $spanProcessor->getCollectedSpans();
        $this->assertCount(2, $collectedSpans);

        /** @var ReadWriteSpanInterface $span */
        $span = array_pop($collectedSpans);
        $this->assertTrue($span->hasEnded());
        $attributes = $span->toSpanData()->getAttributes()->toArray();
        $this->assertArrayHasKey('rpc.service', $attributes);
        $this->assertSame('s3', $attributes['rpc.service']);
        $this->assertArrayHasKey('aws.region', $attributes);
        $this->assertSame('us-east-1', $attributes['aws.region']);

        /** @var ReadWriteSpanInterface $span */
        $span = array_pop($collectedSpans);
        $this->assertTrue($span->hasEnded());
        $attributes = $span->toSpanData()->getAttributes()->toArray();
        $this->assertArrayHasKey('rpc.service', $attributes);
        $this->assertSame('eventbridge', $attributes['rpc.service']);
        $this->assertArrayHasKey('aws.region', $attributes);
        $this->assertSame('ap-southeast-2', $attributes['aws.region']);
    }

    public function testSpansFromDifferentClientsAreNotOverwritingOneAnother()
    {
        try {
            /** @var SqsClient $sqsClient */
            $sqsClient = $this->getTestClient('SQS', ['region' => 'eu-west-1']);
            $this->addMockResults($sqsClient, [[]]);
            /** @var S3Client $s3Client */
            $s3Client = $this->getTestClient('S3', ['region' => 'us-east-1']);
            $this->addMockResults($s3Client, [[]]);

            $spanProcessor = new CollectingSpanProcessor();
            $this->awsSdkInstrumentation->instrumentClients([$sqsClient, $s3Client]);
            $this->awsSdkInstrumentation->setPropagator(new Propagator());
            $this->awsSdkInstrumentation->setTracerProvider(new TracerProvider([$spanProcessor]));
            $this->awsSdkInstrumentation->init();
            $this->awsSdkInstrumentation->activate();

            $sqsClient->listQueuesAsync();
            $s3Client->listBucketsAsync();

            $collectedSpans = $spanProcessor->getCollectedSpans();
            $this->assertCount(2, $collectedSpans);

            /** @var ReadWriteSpanInterface $span */
            $span = array_shift($collectedSpans);
            $attributes = $span->toSpanData()->getAttributes()->toArray();
            $this->assertArrayHasKey('rpc.service', $attributes);
            $this->assertSame('sqs', $attributes['rpc.service']);

            /** @var ReadWriteSpanInterface $span */
            $span = array_shift($collectedSpans);
            $attributes = $span->toSpanData()->getAttributes()->toArray();
            $this->assertArrayHasKey('rpc.service', $attributes);
            $this->assertSame('s3', $attributes['rpc.service']);
        } catch (\Throwable $throwable) {
            /** @phpstan-ignore-next-line  */
            $this->assertFalse(true, sprintf('Exception %s occurred: %s', get_class($throwable), $throwable->getMessage()));
        }
    }

    public function testPreventsRepeatedInstrumentationOfSameClient()
    {
        $clients = [
            'SQS' => $sqsClient = $this->getTestClient('SQS', ['region' => 'eu-west-1']),
            'S3' => $s3Client = $this->getTestClient('S3', ['region' => 'us-east-1']),
            'EventBridge' => $eventBridgeClient = $this->getTestClient('EventBridge', ['region' => 'ap-southeast-2']),
        ];

        $preInstrumentationHandlersCount = array_map(static fn (AwsClientInterface $client) => $client->getHandlerList()->count(), $clients);

        $this->awsSdkInstrumentation->instrumentClients([$sqsClient, $eventBridgeClient]);
        $this->awsSdkInstrumentation->init();
        $this->awsSdkInstrumentation->activate();

        $this->awsSdkInstrumentation->instrumentClients([$s3Client, $eventBridgeClient]);
        $this->awsSdkInstrumentation->init();
        $this->awsSdkInstrumentation->activate();

        foreach ($clients as $name => $client) {
            $this->assertSame(
                $preInstrumentationHandlersCount[$name] + self::HANDLERS_PER_ACTIVATION,
                $client->getHandlerList()->count(),
                sprintf('Failed asserting that %s client was instrumented once', $name)
            );
        }
    }
}
