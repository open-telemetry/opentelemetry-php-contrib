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
use OpenTelemetry\Trace as API;

class Service2
{
    private const LIMIT = 50;

    private $tracer;
    private $carrier;

    public function __construct(array $carrier)
    {
        $this->carrier = $carrier;
    }

    public function useService()
    {
        $Exporter = new OTLPExporter();
        $map = new PropagationMap();

        // Create a tracer object that uses the AWS X-Ray ID Generator to
        // generate trace IDs in the correct format
        $tracer = (new TracerProvider(null, null, new IdGenerator()))
            ->addSpanProcessor(new SimpleSpanProcessor($Exporter))
            ->getTracer('io.opentelemetry.contrib.php');

        // Extract the SpanContext from the carrier
        $spanContext = Propagator::extract($this->carrier, $map);

        // Do some kind of operation
        $i = 0;
        $j = 0;
        while ($i < self::LIMIT) {
            while ($j < self::LIMIT) {
                $j += $i + $j;
                $j++;
            }
            $i++;
        }

        // Create a child span
        $childSpan = $tracer->startActiveSpan('session.second.child.span' . microtime(true), $spanContext, false, API\SpanKind::KIND_CLIENT);

        // Set some dummy attributes
        $childSpan->setAttribute('service_2', 'microservice')
                ->setAttribute('action_item', (string) $j);

        // End child span
        $childSpan->end();

        return $childSpan->getContext();
    }
}
