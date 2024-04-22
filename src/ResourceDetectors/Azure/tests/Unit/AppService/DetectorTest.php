<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Azure\Unit\AppService;

use AssertWell\PHPUnitGlobalState\EnvironmentVariables;
use OpenTelemetry\Azure\AppService\Detector;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SemConv\ResourceAttributes;
use PHPUnit\Framework\TestCase;

class DetectorTest extends TestCase
{
    use EnvironmentVariables;

    const RESOURCE_GROUP = 'resouce_group';
    const OWNER_NAME = 'owner_name';
    const SERVICE_NAME = 'demo-app';
    const RESOURCE_URI = '/subscriptions/owner_name/resourceGroups/resouce_group/providers/Microsoft.Web/sites/demo-app';

    public function tearDown(): void
    {
        self::restoreEnvironmentVariables();
    }

    public function test_valid_app_service_attributes()
    {
        $data = [
            ['azure.app.service.stamp', Detector::ENV_WEBSITE_HOME_STAMPNAME_KEY, 'stamp'],
            [ResourceAttributes::CLOUD_PLATFORM, null, 'azure_app_service'],
            [ResourceAttributes::CLOUD_PROVIDER, null, 'azure'],
            [ResourceAttributes::CLOUD_RESOURCE_ID, Detector::ENV_REGION_NAME_KEY, '/subscriptions/owner_name/resourceGroups/resouce_group/providers/Microsoft.Web/sites/demo-app'],
            [ResourceAttributes::CLOUD_REGION, Detector::ENV_REGION_NAME_KEY, 'westus'],
            [ResourceAttributes::DEPLOYMENT_ENVIRONMENT, Detector::ENV_WEBSITE_SLOT_NAME_KEY, 'testing'],
            [ResourceAttributes::HOST_ID, Detector::ENV_WEBSITE_HOSTNAME_KEY, 'example.com'],
            [ResourceAttributes::SERVICE_INSTANCE_ID, Detector::ENV_WEBSITE_INSTANCE_ID_KEY, uniqid()],
            [ResourceAttributes::SERVICE_NAME, Detector::ENV_WEBSITE_SITE_NAME_KEY, self::SERVICE_NAME],
            [null, Detector::ENV_WEBSITE_RESOURCE_GROUP_KEY, self::RESOURCE_GROUP],
            [null, Detector::ENV_WEBSITE_OWNER_NAME_KEY, self::OWNER_NAME],
        ];
        $expected = [];

        foreach ($data as $item) {
            if ($item[1] !== null) {
                self::setEnvironmentVariable($item[1], $item[2]);
            }

            if ($item[0] !== null) {
                $expected[$item[0]] = $item[2];
            }
        }

        $detector = new Detector();
        $this->assertEquals(
            Attributes::create($expected),
            $detector->getResource()->getAttributes()
        );
    }

    public function test_resource_uri_generation()
    {
        $out = Detector::generateAzureResourceUri(self::SERVICE_NAME, self::RESOURCE_GROUP, self::OWNER_NAME);
        $this->assertEquals(self::RESOURCE_URI, $out);
    }
}
