<?php
/*
 * Copyright The OpenTelemetry Authors
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      https://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);

namespace Examples\Aws\SampleApp1;

require __DIR__ . '/../../../vendor/autoload.php';

use GuzzleHttp\Client;
use Instrumentation\Aws\Xray\AwsXrayIdGenerator;
use OpenTelemetry\Contrib\OtlpGrpc\Exporter as OTLPExporter;
use OpenTelemetry\Sdk\Trace\PropagationMap;
use OpenTelemetry\Sdk\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\Sdk\Trace\TracerProvider;
use OpenTelemetry\Trace as API;
use Propagators\Aws\Xray\AwsXrayPropagator;

// use Aws\S3\S3Client;
// use Aws\Exception\AwsException;

/**
 * This is a sample app that makes an http request
 * to aws.amazon.com or a call to AWS s3 buckets
 * It uses the OTEL GRPC Exporter
 * Sends traces to the aws-otel-collector
 * It will generate one trace that has a child span and uses the
 * AWS X-Ray propagator to inject the context into the carrier.
 */

/**
 * To use aws-sdk-call:
 * Comment line 110
 * Please downgrade guzzle from "^2.0@RC" to "^1.8.2" in composer.json
 * Then run composer require aws/aws-sdk-php
 * Run composer update to make sure everything is up to date
 * Uncomment lines 32-33, 111-134, 151-165
 */

echo 'Starting Sample App' . PHP_EOL;

// Prompts the user to choose which type of request to make
echo 'Which call would you like to make? ' . PHP_EOL;
echo 'Type outgoing-http-call or aws-sdk-call' . PHP_EOL;
$handle = fopen('php://stdin', 'r');
$line = trim(fgets($handle));
if ($line !== 'outgoing-http-call' && $line !== 'aws-sdk-call') {
    echo "ABORTING!\n";
    exit;
}
fclose($handle);

// Create an exporter for exporting traces
// Create a client for making http requests
$Exporter = new OTLPExporter();
$client = new Client();

// Create a tracer object that uses the AWS X-Ray ID Generator to
// generate trace Ids in the correct format
$tracer = (new TracerProvider(null, null, new AwsXrayIdGenerator()))
    ->addSpanProcessor(new SimpleSpanProcessor($Exporter))
    ->getTracer('io.opentelemetry.contrib.php');

// Create a span with the tracer
$span = $tracer->startAndActivateSpan('session.generate.span.' . microtime(true));

// Add some dummy attributes to the parent span (also the root span)
$span->setAttribute('item_A', 'cars')
->setAttribute('item_B', 'motorcycles')
->setAttribute('item_C', 'planes');

// Create a carrier and map to inject the context of the child span into the carrier
$carrier = [];
$map = new PropagationMap();

if ($line === 'outgoing-http-call') {
    // Create a child span for http call
    // Make an HTTP request to take some time up
    // Carrier is injected into the header to simulate a microservice needing the carrier
    $childSpan = $tracer->startAndActivateSpan('session.generate.http.span.' . microtime(true), API\SpanKind::KIND_CLIENT);
    AwsXrayPropagator::inject($childSpan->getContext(), $carrier, $map);

    try {
        $res = $client->request('GET', 'https://aws.amazon.com', ['headers' => $carrier, 'timeout' => 2000,]);
    } catch (\Throwable $e) {
        echo $e->getMessage() . PHP_EOL;
    
        return null;
    }

    // Format and output the trace Id of the childspan
    getTraceId($childSpan);

    $childSpan->end();
} else {
    echo 'The desired function is currently unavailable';
    // // Create a child span for sdk call
    // $childSpan = $tracer->startAndActivateSpan('session.generate.aws.sdk.span.' . microtime(true), API\SpanKind::KIND_CLIENT);
    // AwsXrayPropagator::inject($childSpan->getContext(), $carrier, $map);

    // // Make a call to aws s3 buckets
    // $s3Client = new S3Client([
    //     'profile' => 'default',
    //     'region' => 'us-west-2',
    //     'version' => '2006-03-01'
    // ]);

    // // To create a new bucket uncomment this line
    // // createBucket($s3Client, 'testBucket');

    // $buckets = $s3Client->listBuckets();

    // foreach ($buckets['Buckets'] as $bucket) {
    //     echo $bucket['Name'] . "\n";
    // }

    // // Format and output the trace Id of the childspan
    // getTraceId($childSpan);

    // $childSpan->end();
}

// End both the root span to be able to export the trace
$span->end();

echo PHP_EOL . 'Sample App complete!';
echo PHP_EOL;

// Get a traceId from a span and prints it
function getTraceId($span)
{
    $traceId = $span->getContext()->getTraceId();
    $xrayTraceId = '1-' . substr($traceId, 0, 8) . '-' . substr($traceId, 8);
    echo 'Final trace ID: ' . json_encode(['traceId' => $xrayTraceId]);
}

// // Function for creating s3 buckets
// function createBucket($s3Client, $bucketName)
// {
//     try {
//         $result = $s3Client->createBucket([
//             'Bucket' => $bucketName,
//         ]);
//         return 'The bucket\'s location is: ' .
//             $result['Location'] . '. ' .
//             'The bucket\'s effective URI is: ' .
//             $result['@metadata']['effectiveUri'];
//     } catch (AwsException $e) {
//         return 'Error: ' . $e->getAwsErrorMessage();
//     }
// }
