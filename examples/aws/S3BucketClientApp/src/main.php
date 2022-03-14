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

namespace OpenTelemetry\S3bucketClientApp;

use OpenTelemetry\Aws\Xray\IdGenerator;
use OpenTelemetry\Contrib\OtlpGrpc\Exporter as OTLPExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

require __DIR__ . '/../vendor/autoload.php';

echo <<<EOL
    Starting S3Bucket Client App
    
    Which call would you like to make?
    Type outgoing-http-call or aws-sdk-call
    
    EOL;

// Get user input
$handle = fopen('php://stdin', 'r');
$line = trim(fgets($handle));
fclose($handle);

if (!in_array($line, ['outgoing-http-call', 'aws-sdk-call'])) {
    echo "Abort!\n";
    exit(1);
}

// Create tracer and propagator
$spanProcessor = new SimpleSpanProcessor(new OTLPExporter());
$idGenerator = new IdGenerator();
$tracerProvider =  new TracerProvider([$spanProcessor], null, null, null, $idGenerator);

$tracer = $tracerProvider->getTracer();

// Create root span
$root = $tracer->spanBuilder('root')->startSpan();
$root->activate();

$root->setAttribute('item_A', 'cars')
    ->setAttribute('item_B', 'motorcycles')
    ->setAttribute('item_C', 'planes');

$root->end();
