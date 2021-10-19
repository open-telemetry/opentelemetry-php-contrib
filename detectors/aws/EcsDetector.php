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

namespace Detectors\Aws;

use OpenTelemetry\SDK\Resource\ResourceConstants;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Attributes;

/**
 * The AwsEcsDetector can be used to detect if a process is running in AWS
 * ECS and return a {@link Resource} populated with data about the ECS
 * plugins of AWS ˜X-Ray. Returns an empty Resource if detection fails.
 */
class EcsDetector
{
    private const ECS_METADATA_KEY_V4 = 'ECS_CONTAINER_METADATA_URI_V4';
    private const ECS_METADATA_KEY_V3 = 'ECS_CONTAINER_METADATA_URI';

    private const CONTAINER_ID_LENGTH = 64;
    
    private $processData;

    public function __construct(EcsProcessDataProvider $processData)
    {
        $this->processData = $processData;
    }
    
    /**
     * If running on ECS, runs getContainerId(), getClusterName(), and
     * returns resource with valid extracted values
     * If not running on ECS, returns empty rsource
     */
    public function detect(): ResourceInfo
    {
        // Check if running on ECS by looking for below environment variables
        if (!getenv(self::ECS_METADATA_KEY_V4) && !getenv(self::ECS_METADATA_KEY_V3)) {
            // TODO: add 'Process is not running on ECS' when logs are added
            return ResourceInfo::emptyResource();
        }

        $hostName = $this->processData->getHostname();
        $containerId = $this->getContainerId();

        return !$hostName && !$containerId
            ? ResourceInfo::emptyResource()
            : ResourceInfo::create(new Attributes([
                ResourceConstants::CONTAINER_NAME => $hostName,
                ResourceConstants::CONTAINER_ID => $containerId,
            ]));
    }

    /**
     * Returns the docker ID of the container found
     * in its CGroup file.
     */
    private function getContainerId()
    {
        try {
            $cgroupData = $this->processData->getCgroupData();

            if (!$cgroupData) {
                return null;
            }

            foreach ($cgroupData as $str) {
                if (strlen($str) > self::CONTAINER_ID_LENGTH) {
                    return substr($str, strlen($str) - self::CONTAINER_ID_LENGTH);
                }
            }
        } catch (\Throwable $e) {
            //TODO: add 'Failed to read container ID' when logging is added
        }

        return null;
    }
}

/**
 * Separated from above class to be able to test using
 * mock values through unit tests
 */
class EcsProcessDataProvider
{
    private const DEFAULT_CGROUP_PATH = '/proc/self/cgroup';

    /**
     * Returns the host name of the container the process is in.
     * This would be the os the container is running on,
     * i.e. the platform on which it is deployed
     */
    public function getHostName()
    {
        return php_uname('n');
    }
    
    /**
     * Get data from the Cgroup file
     */
    public function getCgroupData()
    {
        return file(self::DEFAULT_CGROUP_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    }
}
