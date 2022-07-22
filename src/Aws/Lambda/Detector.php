<?php

declare(strict_types=1);

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

namespace OpenTelemetry\Aws\Lambda;

use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceDetectorInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SemConv\ResourceAttributes;

/**
 * The AwsLambdaDetector can be used to detect if a process is running in AWS Lambda
 * and return a {@link Resource} populated with data about the environment.
 * Returns an empty Resource if detection fails.
 */
class Detector implements ResourceDetectorInterface
{
    private const LAMBDA_NAME_ENV = 'AWS_LAMBDA_FUNCTION_NAME';
    private const LAMBDA_VERSION_ENV = 'AWS_LAMBDA_FUNCTION_VERSION';
    private const AWS_REGION_ENV = 'AWS_REGION';
    private const CLOUD_PROVIDER = 'aws';

    public function getResource(): ResourceInfo
    {
        $lambdaName = getenv(self::LAMBDA_NAME_ENV);
        $functionVersion = getenv(self::LAMBDA_VERSION_ENV);
        $awsRegion = getenv(self::AWS_REGION_ENV);

        // The following ternary operations are created because
        // the attributes class will only NOT create a variable
        // when it is set to null. getenv returns false when unsuccessful
        $lambdaName = $lambdaName ? $lambdaName : null;
        $functionVersion = $functionVersion ? $functionVersion : null;
        $awsRegion = $awsRegion ? $awsRegion : null;

        return !$lambdaName && !$awsRegion && !$functionVersion
            ? ResourceInfoFactory::emptyResource()
            : ResourceInfo::create(Attributes::create([
                ResourceAttributes::FAAS_NAME => $lambdaName,
                ResourceAttributes::FAAS_VERSION => $functionVersion,
                ResourceAttributes::CLOUD_REGION => $awsRegion,
                ResourceAttributes::CLOUD_PROVIDER => self::CLOUD_PROVIDER,
            ]));
    }
}
