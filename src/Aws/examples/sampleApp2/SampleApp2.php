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

namespace Examples\Aws\SampleApp2;

require __DIR__ . '/../../../vendor/autoload.php';

use OpenTelemetry\Contrib\Aws\Xray\IdGenerator;
use OpenTelemetry\Contrib\Aws\Xray\Propagator;
use OpenTelemetry\Contrib\OtlpGrpc\Exporter as OTLPExporter;
use OpenTelemetry\SDK\Trace\PropagationMap;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

/**
 * This is a sample app that makes an http request to aws.amazon.com
 * It uses the OTEL GRPC Exporter
 * Sends traces to the aws-otel-collector
 * It will generate one trace that has a two child spans and uses the
 * AWS X-Ray propagator to inject the context into the carrier.
 */

echo 'Starting Sample App' . PHP_EOL;

// Initialize an exporter for exporting traces
// Initialize a map and carrier to use for propagation injection and extraction
$Exporter = new OTLPExporter();
$map = new PropagationMap();
$carrier = [];

// Create a tracer object that uses the AWS X-Ray ID Generator to
// generate trace IDs in the correct format
$tracer = (new TracerProvider(null, null, new IdGenerator()))
    ->addSpanProcessor(new SimpleSpanProcessor($Exporter))
    ->getTracer('io.opentelemetry.contrib.php');

// Create a span (also the root span) with the tracer
$span = $tracer->startAndActivateSpan('session.generate.span.' . microtime(true));

// Add some dummy attributes to the parent span
$span->setAttribute('item_A', 'cars')
->setAttribute('item_B', 'motorcycles')
->setAttribute('item_C', 'planes');

// Inject the context of the child span into the carrier to pass to the first service1
// The tracer is passed to each service for convinience of not creating another tracer
// in the service.

// TODO: The next step for testing propagation would be to create two separate
// web application, each making a request from a client front end.
Propagator::inject($span->getContext(), $carrier, $map);
$service1 = new Service1($carrier);
$childSpanContext = $service1->useService();

// Inject the context of the child span into the carrier to pass to the first service2
Propagator::inject($childSpanContext, $carrier, $map);
$service2 = new Service2($carrier);
$childSpanContext2 = $service2->useService();

// Format and output the trace ID of the a child span
$traceId = $childSpanContext2->getTraceId();
$xrayTraceId = '1-' . substr($traceId, 0, 8) . '-' . substr($traceId, 8);
echo 'Child span trace ID after service 2: ' . json_encode(['traceId' => $xrayTraceId]);

// End the parent span
$span->end();

echo PHP_EOL . 'Sample App complete!';
echo PHP_EOL;
