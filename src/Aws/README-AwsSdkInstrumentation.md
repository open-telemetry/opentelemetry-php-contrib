# AWS SDK Instrumentation for OpenTelemetry PHP 
This package supports manual instrumentation for the AWS SDK for PHP. For more information on how to use the AWS SDK, see the [AWS SDK for PHP Developer's Guide](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/welcome.html). 


## Using the AWS SDK Instrumentation with AWS X-Ray


```
use OpenTelemetry\Instrumentation\AwsSdk\AwsSdkInstrumentation;

// Initialize Span Processor, X-Ray ID generator, Tracer Provider, and Propagator
$spanProcessor = new SimpleSpanProcessor(new OTLPExporter());
$xrayIdGenerator = new IdGenerator();
$tracerProvider = new TracerProvider($spanProcessor, null, null, null, $xrayIdGenerator);
$xrayPropagator = new Propagator();

// Create new instance of AWS SDK Instrumentation class
$awssdkinstrumentation = new  AwsSdkInstrumentation();

// Configure AWS SDK Instrumentation with Propagator and set Tracer Provider (created above)
$awssdkinstrumentation->setPropagator($xrayPropagator);
$awssdkinstrumentation->setTracerProvider($tracerProvider);

// Create and activate root span
$root = $awssdkinstrumentation->getTracer()->spanBuilder('AwsSDKInstrumentation')->setSpanKind(SpanKind::KIND_SERVER)->startSpan();
$rootScope = $root->activate();

// Initialize all AWS Client instances
$s3Client = new S3Client([
    'region' => 'us-west-2',
    'version' => '2006-03-01',
]);

// Pass client instances to AWS SDK
$awssdkinstrumentation->instrumentClients([$s3Client]);

// Activate Instrumentation -- all AWS Client calls will be automatically instrumented
$awssdkinstrumentation->activate();

// Make S3 client call
$result = $s3Client->listBuckets();

// End the root span after all the calls to the AWS SDK have been made
$root->end();
$rootScope->detach();

```

## Useful Links and Resources 
For more information on how to use the AWS SDK for PHP with AWS X-Ray and using the [AWS Distro for OpenTelemetry](https://aws-otel.github.io/), please see the [aws-otel-php repository](https://github.com/aws-observability/aws-otel-php).
