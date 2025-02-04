<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Aws\Integration;

use OpenTelemetry\Aws\AwsSdkInstrumentation;
use OpenTelemetry\Aws\Xray\Propagator;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

class AwsSdkInstrumentationTest extends TestCase
{
    use UsesServiceTrait;

    private AwsSdkInstrumentation $awsSdkInstrumentation;

    protected function setUp(): void
    {
        $this->awsSdkInstrumentation = new AwsSdkInstrumentation();
    }

    public function testProperClientNameAndRegionIsPassedToSpanForSingleClientCall()
    {
        $sqsClient = $this->getTestClient('SQS', ['region' => 'eu-west-1']);
        $s3Client = $this->getTestClient('S3', ['region' => 'us-east-1']);
        $this->addMockResults($s3Client, [[]]);
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
        $sqsClient = $this->getTestClient('SQS', ['region' => 'eu-west-1']);
        $s3Client = $this->getTestClient('S3', ['region' => 'us-east-1']);
        $this->addMockResults($s3Client, [[], []]);
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
        $sqsClient = $this->getTestClient('SQS', ['region' => 'eu-west-1']);
        $s3Client = $this->getTestClient('S3', ['region' => 'us-east-1']);
        $this->addMockResults($s3Client, [[]]);
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
                    'Detail' => '{}'
                ]
            ]
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
}
