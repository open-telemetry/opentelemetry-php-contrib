<?php

declare(strict_types=1);

namespace Tests;

use Bref\Context\Context;
use Bref\Context\Context as BrefContext;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Contrib\Aws\Lambda\AwsLambdaWrapper;
use OpenTelemetry\Contrib\Aws\Lambda\Detector as LambdaDetector;
use OpenTelemetry\Contrib\Aws\Xray\IdGenerator;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\TraceAttributes;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class AwsLambdaWrapperTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset the singleton instance and cold-start flag before each test
        $refClass = new ReflectionClass(AwsLambdaWrapper::class);

        $instanceProp = $refClass->getProperty('instance');
        /** @psalm-suppress UnusedMethodCall */
        $instanceProp->setAccessible(true);
        $instanceProp->setValue(null, null);
    }

    public function testWrapHandlerEmitsRootSpanWithCorrectAttributes(): void
    {
        // 1) Set up the expected Lambda environment
        putenv('AWS_LAMBDA_FUNCTION_NAME=my-lambda');
        $lambdaContext = [
            'awsRequestId'       => 'request-1',
            'invokedFunctionArn' => 'arn:aws:lambda:us-east-1:123:function:myFunc:PROD',
            'traceId'            => '',
        ];
        /** @psalm-suppress PossiblyFalseOperand */
        putenv('LAMBDA_INVOCATION_CONTEXT=' . json_encode($lambdaContext));

        // 2) Build an in-memory exporter and tracer provider
        $exporter      = new InMemoryExporter();
        $spanProcessor = new SimpleSpanProcessor($exporter);

        $defaultResource = ResourceInfoFactory::defaultResource();
        $lambdaResource  = (new LambdaDetector())->getResource();
        $resource        = $defaultResource->merge($lambdaResource);

        $tracerProvider = new TracerProvider(
            $spanProcessor,
            null,
            $resource,
            null,
            new IdGenerator()
        );
        $tracer = $tracerProvider->getTracer('php-lambda');

        // 3) Inject our test tracer into the wrapper
        $wrapper = AwsLambdaWrapper::getInstance();
        $wrapper->setTracer($tracer);

        // 4) Wrap a no-op handler
        $wrapped = $wrapper->WrapHandler(
            /** @psalm-suppress UnusedClosureParam */
            function (array $event, Context $ctx): array {
                return [
                    'statusCode' => 200,
                    'headers'    => ['Content-Type' => 'text/plain'],
                    'body'       => 'OK',
                ];
            }
        );

        // 5) Prepare a faux HTTP event and a Context instance
        $event = [
            'rawPath' => '/outgoing-http-call',
            'headers' => [],
        ];

        // Create a Bref\Context\Context object *without* invoking its constructor
        $rc      = new \ReflectionClass(BrefContext::class);
        $context = $rc->newInstanceWithoutConstructor();

        // 6) Invoke the wrapped handler
        $response = $wrapped($event, $context);
        $this->assertSame(200, $response['statusCode']);
        $this->assertSame('OK', $response['body']);

        // 7) Flush and collect spans
        $tracerProvider->shutdown();
        $spans = $exporter->getSpans();
        $this->assertCount(1, $spans, 'Exactly one root span should be emitted');

        $rootSpan = $spans[0];

        // 8) Assert the span’s name and semantic attributes
        $this->assertSame('my-lambda', $rootSpan->getName());

        $attrs = $rootSpan->getAttributes();
        $this->assertSame('other', $attrs->get(TraceAttributes::FAAS_TRIGGER));
        $this->assertTrue($attrs->get(TraceAttributes::FAAS_COLDSTART));
        $this->assertSame('my-lambda', $attrs->get(TraceAttributes::FAAS_NAME));
        $this->assertSame('request-1', $attrs->get(TraceAttributes::FAAS_INVOCATION_ID));
        $this->assertSame('arn:aws:lambda:us-east-1:123:function:myFunc', $attrs->get(TraceAttributes::CLOUD_RESOURCE_ID));
        $this->assertSame('123', $attrs->get(TraceAttributes::CLOUD_ACCOUNT_ID));
    }

    public function testGetAccountIdExtractsCorrectly(): void
    {
        $refClass = new ReflectionClass(AwsLambdaWrapper::class);
        $method   = $refClass->getMethod('getAccountId');
        /** @psalm-suppress UnusedMethodCall */
        $method->setAccessible(true);

        // null or empty input returns null
        $this->assertNull($method->invoke(null, null));
        $this->assertNull($method->invoke(null, ''));

        // valid ARN yields the 5th segment
        $arn = 'arn:aws:lambda:us-west-2:123456789012:function:myFunc';
        $this->assertSame('123456789012', $method->invoke(null, $arn));

        // too-short ARN yields null
        $this->assertNull($method->invoke(null, 'arn:aws:lambda:us-west-2'));
    }

    public function testGetCloudResourceIdExtractsCorrectly(): void
    {
        $refClass = new ReflectionClass(AwsLambdaWrapper::class);
        $method   = $refClass->getMethod('getCloudResourceId');
        /** @psalm-suppress UnusedMethodCall */
        $method->setAccessible(true);

        // null or empty input returns null
        $this->assertNull($method->invoke(null, null));
        $this->assertNull($method->invoke(null, ''));

        // ARN with version/alias is trimmed at the 7th segment
        $fullArn   = 'arn:aws:lambda:us-east-1:123:function:myFunc:PROD';
        $expected1 = 'arn:aws:lambda:us-east-1:123:function:myFunc';
        $this->assertSame($expected1, $method->invoke(null, $fullArn));

        // ARN without version stays intact
        $shortArn = 'arn:aws:lambda:us-east-1:123:function:myFunc';
        $this->assertSame($shortArn, $method->invoke(null, $shortArn));
    }

    public function testTracerIsConfigurable(): void
    {
        $wrapper = AwsLambdaWrapper::getInstance();
        $original = $wrapper->getTracer();
        $this->assertInstanceOf(TracerInterface::class, $original);

        $mockTracer = $this->createMock(TracerInterface::class);
        $wrapper->setTracer($mockTracer);
        $this->assertSame($mockTracer, $wrapper->getTracer());
    }
}
