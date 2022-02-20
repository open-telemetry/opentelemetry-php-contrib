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

use OpenTelemetry\SDK\Trace\IdGeneratorInterface;
use OpenTelemetry\SDK\Trace\RandomIdGenerator;

/**
 * An ID generator that generates trace IDs that conforms to AWS X-Ray format
 * Refer to the AWS X-Ray documentation:
 * https://docs.aws.amazon.com/xray/latest/devguide/xray-api-sendingdata.html#xray-api-traceids
 */
class IdGenerator implements IdGeneratorInterface
{
    private const TRACE_ID_RANDOM_HEX_LENGTH = 24;

    private RandomIdGenerator $randomIdGenerator;

    /**
     * Constructor creates randomIdGenerator instance
     */
    public function __construct(?RandomIdGenerator $randomIdGenerator = null)
    {
        $this->randomIdGenerator = $randomIdGenerator ?? new RandomIdGenerator();
    }

    /**
     * Returns a trace ID in AWS X-Ray format.
     * Dashes and version number are done in inject() function of Propagator
     *
     * Returns an epoch timestamp converted to
     * 8 hexadecimal digits, and random 96-bit string converted to a
     * 24 character hexadecimal string in one 32 character string.
     *
     * Example: 60cd399d76b435b5411c66f29b238014
     */
    public function generateTraceId(): string
    {
        return dechex(time()) . substr($this->randomIdGenerator->generateTraceId(), 0, self::TRACE_ID_RANDOM_HEX_LENGTH);
    }

    /**
     * Returns a random 64-bit string in the form of 16
     * hexadecimal characters.
     */
    public function generateSpanId(): string
    {
        return $this->randomIdGenerator->generateSpanId();
    }
}
