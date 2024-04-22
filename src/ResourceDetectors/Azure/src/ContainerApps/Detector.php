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

namespace OpenTelemetry\Azure\ContainerApps;

use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceDetectorInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SemConv\ResourceAttributes;

/**
 * The AzureAppServiceDetector can be used to detect if a process is running in
 * a Azure App Service and return a Resource populated with metadata service.
 * Returns an empty Resource if detection fails.
 */
class Detector implements ResourceDetectorInterface
{
    public const CLOUD_PROVIDER = 'azure';
    public const CLOUD_PLATFORM = 'azure_container_apps';
    public const ENV_CONTAINER_APP_NAME_KEY = 'CONTAINER_APP_NAME';
    public const ENV_CONTAINER_APP_REPLICA_NAME_KEY = 'CONTAINER_APP_REPLICA_NAME';
    public const ENV_CONTAINER_APP_REVISION_KEY = 'CONTAINER_APP_REVISION';

    public function getResource(): ResourceInfo
    {
        $name = getenv(self::ENV_CONTAINER_APP_NAME_KEY);

        if ($name == false) {
            return ResourceInfo::emptyResource();
        }

        $attributes = [
            ResourceAttributes::CLOUD_PLATFORM => self::CLOUD_PLATFORM,
            ResourceAttributes::CLOUD_PROVIDER => self::CLOUD_PROVIDER,
            ResourceAttributes::SERVICE_INSTANCE_ID => getenv(self::ENV_CONTAINER_APP_REPLICA_NAME_KEY),
            ResourceAttributes::SERVICE_NAME => $name,
            ResourceAttributes::SERVICE_VERSION => getenv(self::ENV_CONTAINER_APP_REVISION_KEY),
        ];

        return ResourceInfo::create(Attributes::create($attributes), ResourceAttributes::SCHEMA_URL);
    }
}
