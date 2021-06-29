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

namespace Propagators\Aws\Xray;

use OpenTelemetry\Sdk\Trace\SpanContext;
use OpenTelemetry\Trace as API;

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
class AwsXrayPropagator implements API\TextMapFormatPropagator
{
    public const AWSXRAY_TRACE_ID_HEADER = 'X-Amzn-Trace-Id';
    private const HEADER_TYPE = 'string';
    private const VERSION_NUMBER = '1';
    private const TRACE_HEADER_DELIMITER = ';';
    private const KV_DELIMITER = '=';

    private const TRACE_ID_KEY = 'Root';
    private const TRACE_ID_LENGTH = 32;
    private const TRACE_ID_VERSION = '1';
    private const TRACE_ID_DELIMITER = '-';
    private const TRACE_ID_TIMESTAMP_LENGTH = 8;
    private const VERSION_NUMBER_INDEX = 0;
    private const TIMESTAMP_INDEX = 1;
    private const RANDOM_HEX_INDEX = 2;

    private const PARENT_ID_KEY = 'Parent';
    private const SPAN_ID_LENGTH = 16;
    
    private const SAMPLED_FLAG_KEY = 'Sampled';
    private const IS_SAMPLED = '1';
    private const NOT_SAMPLED = '0';
    
    /**
     * Returns list of fields used by HTTPTextFormat
     */
    public static function fields(): array
    {
        return [self::AWSXRAY_TRACE_ID_HEADER];
    }

    /**
     * Creates and injects the traceHeader
     * X-Amzn-Trace-Id: Root={traceId};Parent={parentId};Sampled={samplingFlag}
     * X-Amzn-Trace-Id: Root=1-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=1
     */
    public static function inject(API\SpanContext $context, &$carrier, API\PropagationSetter $setter): void
    {
        $traceId = $context->getTraceId();
        $spanId = $context->getSpanId();

        if (!SpanContext::isValidSpanId($spanId) || !SpanContext::isValidTraceId($traceId)) {
            return;
        }

        $timestamp = substr($traceId, 0, self::TRACE_ID_TIMESTAMP_LENGTH);
        $randomHex = substr($traceId, self::TRACE_ID_TIMESTAMP_LENGTH);
        $samplingFlag = $context->isSampled() ? self::IS_SAMPLED : self::NOT_SAMPLED;

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
    public static function extract($carrier, API\PropagationGetter $getter): API\SpanContext
    {
        // Extract the traceHeader using the getter from the carrier
        $traceHeader = $getter->get($carrier, self::AWSXRAY_TRACE_ID_HEADER);
        
        // If it is null it creates a dummy header with INVALID_TRACE and INVALID_SPAN
        // Function: restore(string $traceId, string $spanId, bool $sampled = false,
        // bool $isRemote = false, ?API\TraceState $traceState = null): SpanContext
        if ($traceHeader == null || gettype($traceHeader) !== self::HEADER_TYPE) {
            return SpanContext::getInvalid();
        }

        $headerComponents = explode(self::TRACE_HEADER_DELIMITER, $traceHeader);
        $parsedTraceId = SpanContext::INVALID_TRACE;
        $parsedSpanId = SpanContext::INVALID_SPAN;
        $sampledFlag = '';

        foreach ($headerComponents as $component) {
            // The key value pair of a component of the traceHeader.
            // Example: key = 'Parent' value = '53995c3f42cd8ad8'
            $componentPair = explode(self::KV_DELIMITER, $component);

            if ($componentPair[0] === self::PARENT_ID_KEY) {
                $parsedSpanId = $componentPair[1];
            } elseif ($componentPair[0] === self::TRACE_ID_KEY) {
                $parsedTraceId = self::parseTraceId($componentPair[1]);
            } elseif ($componentPair[0] === self::SAMPLED_FLAG_KEY) {
                $sampledFlag = $componentPair[1];
            }
        }

        if (SpanContext::isValidSpanId($parsedSpanId) && SpanContext::isValidTraceId($parsedTraceId) && self::isValidSampled($sampledFlag)) {
            return SpanContext::restore($parsedTraceId, $parsedSpanId, $sampledFlag === self::IS_SAMPLED, true);
        }

        return SpanContext::getInvalid();
    }

    /**
     * Parse Xray format trace Id
     * Returns parsedId if successful, else returns invalid trace Id
     */
    private static function parseTraceId($traceId): string
    {
        $parsedTraceId = SpanContext::INVALID_TRACE;
        $traceIdParts = explode(self::TRACE_ID_DELIMITER, $traceId);
        if (count($traceIdParts) > 1 && SpanContext::isValidTraceId($traceIdParts[self::TIMESTAMP_INDEX] .
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
    private static function isValidSampled($samplingFlag): bool
    {
        return $samplingFlag === self::IS_SAMPLED || $samplingFlag === self::NOT_SAMPLED;
    }
}
