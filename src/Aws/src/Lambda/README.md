# AWS Lambda Instrumentation for OpenTelemetry PHP 
This package supports manual instrumentation for the AWS Lambda functions written in PHP. PHP Lambda functions can be deployed using [Bref](https://bref.sh/) which this package primary depends on when doing instrumentation.


## Using the AWS Lambda Instrumentation with AWS Lambda Functions
Below is a example on how to setup AWS Lambda Instrumentation:

1. Follow the steps in this [README](../../README.md) to install the AWS Contrib Dependency.
2. Follow the example below to apply the wrapper and get your handler function instrumented:
```php
<?php
// index.php

require __DIR__ . '/vendor/autoload.php';

use Bref\Context\Context;
use OpenTelemetry\Contrib\Aws\Lambda\AwsLambdaWrapper;

// Get AwsLambdaWrapper Instance which already constructs a Tracer to be used for creating spans
$wrapper = AwsLambdaWrapper::getInstance();

// Use the default tracer created by the wrapper to manually create and instrument other spans.
$tracer = $wrapper->getTracer();

// Alternatively, you can create your own tracer and then call $wrapper->setTracer($customTracer)

// Your PHP Handler Function and logic.
$handlerFunction = function (array $event, Context $context) use ($tracer): array {
     // .... handler code using $tracer to manually instrument spans.
    return [
        'statusCode' => 404,
        'headers'    => ['Content-Type' => 'text/plain'],
        'body'       => 'Not Found',
    ];
};

// The WrapHandler Function is where you pass the original function and it gets instrumented
return $wrapper->WrapHandler($handlerFunction);
```