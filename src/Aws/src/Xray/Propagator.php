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

namespace OpenTelemetry\Aws\Xray;

use OpenTelemetry\API\Trace as API;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Trace\Span;

/**
 * Implementation of AWS Trace Header Protocol
 * See below:
 * https://docs.aws.amazon.com/xray/latest/devguide/xray-concepts.html#xray-concepts-tracingheader
 *
 * Propagator serializes Span Context to/from AWS X-Ray headers.
 * Example AWS X-Ray format:
 *
 * X-Amzn-Trace-Id: Root={traceId};Parent={parentId};Sampled={samplingFlag}
 * X-Amzn-Trace-Id: Root=1-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=1
 */
class Propagator implements TextMapPropagatorInterface
{
    public const AWSXRAY_TRACE_ID_HEADER = 'x-amzn-trace-id';
    private const VERSION_NUMBER = '1';
    private const TRACE_HEADER_DELIMITER = ';';
    private const KV_DELIMITER = '=';

    private const TRACE_ID_KEY = 'Root';
    private const TRACE_ID_DELIMITER = '-';
    private const TRACE_ID_TIMESTAMP_LENGTH = 8;
    private const VERSION_NUMBER_INDEX = 0;
    private const TIMESTAMP_INDEX = 1;
    private const RANDOM_HEX_INDEX = 2;

    private const PARENT_ID_KEY = 'Parent';

    private const SAMPLED_FLAG_KEY = 'Sampled';
    private const IS_SAMPLED = '1';
    private const NOT_SAMPLED = '0';
    
    /**
     * Returns list of fields used by HTTPTextFormat
     */
    public function fields(): array
    {
        return [self::AWSXRAY_TRACE_ID_HEADER];
    }

    /**
     * Creates and injects the traceHeader
     * X-Amzn-Trace-Id: Root={traceId};Parent={parentId};Sampled={samplingFlag}
     * X-Amzn-Trace-Id: Root=1-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=1
     */
    public function inject(&$carrier, ?PropagationSetterInterface $setter = null, ?ContextInterface $context = null): void
    {
        $setter = $setter ?? ArrayAccessGetterSetter::getInstance();
        $context = $context ?? Context::getCurrent();
        $spanContext = Span::fromContext($context)->getContext();

        if (!$spanContext->isValid()) {
            return;
        }

        $traceId = $spanContext->getTraceId();
        $spanId = $spanContext->getSpanId();

        $timestamp = substr($traceId, 0, self::TRACE_ID_TIMESTAMP_LENGTH);
        $randomHex = substr($traceId, self::TRACE_ID_TIMESTAMP_LENGTH);
        $samplingFlag = $spanContext->isSampled() ? self::IS_SAMPLED : self::NOT_SAMPLED;

        $traceHeader = self::TRACE_ID_KEY . self::KV_DELIMITER . self::VERSION_NUMBER .
            self::TRACE_ID_DELIMITER . $timestamp . self::TRACE_ID_DELIMITER . $randomHex .
            self::TRACE_HEADER_DELIMITER . self::PARENT_ID_KEY . self::KV_DELIMITER . $spanId .
            self::TRACE_HEADER_DELIMITER . self::SAMPLED_FLAG_KEY . self::KV_DELIMITER . $samplingFlag;
        $setter->set($carrier, self::AWSXRAY_TRACE_ID_HEADER, $traceHeader);
    }

    /**
     * Extracts the span context from the carrier to transfer to the next service
     * This function will parse all parts of the header and validate that it is
     * formatted properly to AWS X-ray standards
     */
    public function extract($carrier, ?PropagationGetterInterface $getter = null, ?ContextInterface $context = null): ContextInterface
    {
        $getter = $getter ?? ArrayAccessGetterSetter::getInstance();
        $context = $context ?? Context::getCurrent();

        // Extract the traceHeader using the getter from the carrier
        $traceHeader = $getter->get($carrier, self::AWSXRAY_TRACE_ID_HEADER);

        if (!$traceHeader) {
            return $context;
        }

        $headerComponents = explode(self::TRACE_HEADER_DELIMITER, $traceHeader);
        $parsedTraceId = API\SpanContextValidator::INVALID_TRACE;
        $parsedSpanId = API\SpanContextValidator::INVALID_SPAN;
        $sampledFlag = '';

        foreach ($headerComponents as $component) {
            // The key value pair of a component of the traceHeader.
            // Example: key = 'Parent' value = '53995c3f42cd8ad8'
            $componentPair = explode(self::KV_DELIMITER, $component);

            if ($componentPair[0] === self::PARENT_ID_KEY) {
                $parsedSpanId = $componentPair[1];
            } elseif ($componentPair[0] === self::TRACE_ID_KEY) {
                $parsedTraceId = $this->parseTraceId($componentPair[1]);
            } elseif ($componentPair[0] === self::SAMPLED_FLAG_KEY) {
                $sampledFlag = $componentPair[1];
            }
        }

        if (!$this->isValidSampled($sampledFlag)) {
            return $context;
        }

        $spanContext = SpanContext::createFromRemoteParent(
            $parsedTraceId,
            $parsedSpanId,
            self::IS_SAMPLED === $sampledFlag ? API\TraceFlags::SAMPLED : API\TraceFlags::DEFAULT
        );

        if ($spanContext->isValid()) {
            $context = $context->withContextValue(Span::wrap($spanContext));
        }

        // TODO: Apply parsed baggage.

        return $context;
    }

    /**
     * Parse Xray format trace Id
     * Returns parsedId if successful, else returns invalid trace Id
     */
    private function parseTraceId($traceId): string
    {
        $parsedTraceId = API\SpanContextValidator::INVALID_TRACE;
        $traceIdParts = explode(self::TRACE_ID_DELIMITER, $traceId);
        if (count($traceIdParts) > 1 && API\SpanContextValidator::isValidTraceId($traceIdParts[self::TIMESTAMP_INDEX] .
        $traceIdParts[self::RANDOM_HEX_INDEX]) && $traceIdParts[self::VERSION_NUMBER_INDEX] === self::VERSION_NUMBER) {
            $parsedTraceId = $traceIdParts[self::TIMESTAMP_INDEX] . $traceIdParts[self::RANDOM_HEX_INDEX];
        }

        return $parsedTraceId;
    }

    /**
     * Checks whether the sampling decision is valid or not.
     * Valid sampling decisions must be a 0 or 1 string
     * This is AWS specific since it separates the sampled flag from  other trace flags
     * This can be moved to SpanContext file at a later date if desired by other propagators
     */
    private function isValidSampled($samplingFlag): bool
    {
        return $samplingFlag === self::IS_SAMPLED || $samplingFlag === self::NOT_SAMPLED;
    }
}
