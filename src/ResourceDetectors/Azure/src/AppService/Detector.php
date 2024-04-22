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

namespace OpenTelemetry\Azure\AppService;

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
    public const CLOUD_PLATFORM = 'azure_app_service';
    public const ENV_CONTAINER_APP_NAME_KEY = 'CONTAINER_APP_NAME';
    public const ENV_REGION_NAME_KEY = 'REGION_NAME';
    public const ENV_WEBSITE_HOME_STAMPNAME_KEY = 'WEBSITE_HOME_STAMPNAME';
    public const ENV_WEBSITE_HOSTNAME_KEY = 'WEBSITE_HOSTNAME';
    public const ENV_WEBSITE_INSTANCE_ID_KEY = 'WEBSITE_INSTANCE_ID';
    public const ENV_WEBSITE_OWNER_NAME_KEY = 'WEBSITE_OWNER_NAME';
    public const ENV_WEBSITE_RESOURCE_GROUP_KEY = 'WEBSITE_RESOURCE_GROUP';
    public const ENV_WEBSITE_SITE_NAME_KEY = 'WEBSITE_SITE_NAME';
    public const ENV_WEBSITE_SLOT_NAME_KEY = 'WEBSITE_SLOT_NAME';

    public function getResource(): ResourceInfo
    {
        $name = getenv(self::ENV_WEBSITE_SITE_NAME_KEY);
        $groupName = getenv(self::ENV_WEBSITE_RESOURCE_GROUP_KEY);
        $subscriptionId = getenv(self::ENV_WEBSITE_OWNER_NAME_KEY);

        if ($name == false || $groupName == false || $subscriptionId == false) {
            return ResourceInfo::emptyResource();
        }

        $attributes = [
            'azure.app.service.stamp' => getenv(self::ENV_WEBSITE_HOME_STAMPNAME_KEY),
            ResourceAttributes::CLOUD_PLATFORM => self::CLOUD_PLATFORM,
            ResourceAttributes::CLOUD_PROVIDER => self::CLOUD_PROVIDER,
            ResourceAttributes::CLOUD_REGION => getenv(self::ENV_REGION_NAME_KEY),
            ResourceAttributes::CLOUD_RESOURCE_ID => self::generateAzureResourceUri($name, $groupName, $subscriptionId),
            ResourceAttributes::DEPLOYMENT_ENVIRONMENT => getenv(self::ENV_WEBSITE_SLOT_NAME_KEY),
            ResourceAttributes::HOST_ID => getenv(self::ENV_WEBSITE_HOSTNAME_KEY),
            ResourceAttributes::SERVICE_INSTANCE_ID => getenv(self::ENV_WEBSITE_INSTANCE_ID_KEY),
            ResourceAttributes::SERVICE_NAME => $name,
        ];

        return ResourceInfo::create(Attributes::create($attributes), ResourceAttributes::SCHEMA_URL);
    }

    public static function generateAzureResourceUri(string $siteName, string $groupName, string $subscriptionId): string
    {
        return '/subscriptions/' . $subscriptionId . '/resourceGroups/' . $groupName . '/providers/Microsoft.Web/sites/' . $siteName;
    }
}
