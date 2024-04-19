<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Azure\Unit\ContainerApps;

use AssertWell\PHPUnitGlobalState\EnvironmentVariables;
use OpenTelemetry\Azure\ContainerApps\Detector;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SemConv\ResourceAttributes;
use PHPUnit\Framework\TestCase;

class DetectorTest extends TestCase
{
    use EnvironmentVariables;

    const CONTAINER_APP_NAME = 'container_app_name';
    const CONTAINER_APP_REPLICA_NAME = 'container_app_replica_name';
    const CONTAINER_APP_REVISION = 'container_app_revision';

    public function tearDown(): void
    {
        self::restoreEnvironmentVariables();
    }

    public function test_valid_container_apps_attributes()
    {
        $data = [
            [ResourceAttributes::CLOUD_PLATFORM, null, Detector::CLOUD_PLATFORM],
            [ResourceAttributes::CLOUD_PROVIDER, null, Detector::CLOUD_PROVIDER],
            [ResourceAttributes::SERVICE_INSTANCE_ID, Detector::ENV_CONTAINER_APP_REPLICA_NAME_KEY, self::CONTAINER_APP_REPLICA_NAME],
            [ResourceAttributes::SERVICE_NAME, Detector::ENV_CONTAINER_APP_NAME_KEY, self::CONTAINER_APP_NAME],
            [ResourceAttributes::SERVICE_VERSION, Detector::ENV_CONTAINER_APP_REVISION_KEY, self::CONTAINER_APP_REVISION],
        ];
        $expected = [];

        foreach ($data as $item) {
            if ($item[1] !== null) {
                self::setEnvironmentVariable($item[1], $item[2]);
            }

            $expected[$item[0]] = $item[2];
        }

        $detector = new Detector();
        $this->assertEquals(
            Attributes::create($expected),
            $detector->getResource()->getAttributes()
        );
    }
}
