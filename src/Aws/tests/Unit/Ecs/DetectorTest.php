<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Aws\Unit\Ecs;

use OpenTelemetry\Aws\Ecs\DataProvider;
use OpenTelemetry\Aws\Ecs\Detector;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SemConv\ResourceAttributes;
use PHPUnit\Framework\TestCase;

class DetectorTest extends TestCase
{
    private const VALID_CGROUP_DATA = ['abcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklm', '', 'abcdefghijk'];
    private const MULTIVALID_CGROUP_DATA = ['abcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklm',
                                            'bbbbjklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklm', 'abcdefghijk', ];
    private const INVALID_CGROUP_LENGTH = ['abcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijkl', 'abcdefghijk',' '];
    private const INVALID_CGROUP_EMPTY = []; // empty
    private const INVALID_CGROUP_VALUES = ['','','']; // empty

    private const HOST_NAME = 'abcd.test.testing.com';

    private const EXTRACTED_CONTAINER_ID = 'bcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklm';

    private const ECS_ENV_VAR_V4_KEY = 'ECS_CONTAINER_METADATA_URI_V4';
    private const ECS_ENV_VAR_V3_KEY = 'ECS_CONTAINER_METADATA_URI';
    private const ECS_ENV_VAR_V4_VAL = 'ecs_metadata_v4_uri';
    private const ECS_ENV_VAR_V3_VAL = 'ecs_metadata_v3_uri';

    /**
     * @test
     */
    public function TestValidCgroupData()
    {
        putenv(self::ECS_ENV_VAR_V4_KEY . '=' . self::ECS_ENV_VAR_V4_VAL);

        $mockData = $this->createMock(DataProvider::class);

        $mockData->method('getCgroupData')->willReturn(self::VALID_CGROUP_DATA);
        $mockData->method('getHostName')->willReturn(self::HOST_NAME);

        $detector = new Detector($mockData);

        $this->assertEquals(ResourceInfo::create(
            Attributes::create(
                [
                    ResourceAttributes::CONTAINER_NAME => self::HOST_NAME,
                    ResourceAttributes::CONTAINER_ID => self::EXTRACTED_CONTAINER_ID,
                ]
            )
        ), $detector->getResource());

        //unset environment variable
        putenv(self::ECS_ENV_VAR_V4_KEY);
    }

    /**
     * @test
     */
    public function TestFirstValidCgroupData()
    {
        putenv(self::ECS_ENV_VAR_V4_KEY . '=' . self::ECS_ENV_VAR_V4_VAL);

        $mockData = $this->createMock(DataProvider::class);

        $mockData->method('getCgroupData')->willReturn(self::MULTIVALID_CGROUP_DATA);
        $mockData->method('getHostName')->willReturn(self::HOST_NAME);

        $detector = new Detector($mockData);

        $this->assertEquals(ResourceInfo::create(
            Attributes::create(
                [
                    ResourceAttributes::CONTAINER_NAME => self::HOST_NAME,
                    ResourceAttributes::CONTAINER_ID => self::EXTRACTED_CONTAINER_ID,
                ]
            )
        ), $detector->getResource());

        //unset environment variable
        putenv(self::ECS_ENV_VAR_V4_KEY);
    }

    /**
     * @test
     */
    public function TestIsRunningOnEcsReturnsEmpty()
    {
        $mockData = $this->createMock(DataProvider::class);

        $mockData->method('getCgroupData')->willReturn(self::MULTIVALID_CGROUP_DATA);
        $mockData->method('getHostName')->willReturn(self::HOST_NAME);

        $detector = new Detector($mockData);

        $this->assertEquals(ResourceInfoFactory::emptyResource(), $detector->getResource());
    }

    /**
     * @test
     */
    public function TestReturnOnlyHostnameWithoutCgroupFile()
    {
        // Test other version (v3)
        putenv(self::ECS_ENV_VAR_V3_KEY . '=' . self::ECS_ENV_VAR_V3_VAL);

        $mockData = $this->createMock(DataProvider::class);

        $mockData->method('getCgroupData')->willReturn(false);
        $mockData->method('getHostName')->willReturn(self::HOST_NAME);

        $detector = new Detector($mockData);

        $this->assertEquals(ResourceInfo::create(
            Attributes::create(
                [
                    ResourceAttributes::CONTAINER_NAME => self::HOST_NAME,
                ]
            )
        ), $detector->getResource());

        //unset environment variable
        putenv(self::ECS_ENV_VAR_V4_KEY);
    }

    /**
     * @test
     */
    public function TestReturnOnlyHostnameWithInvalidCgroupFile()
    {
        // Test other version (v3)
        putenv(self::ECS_ENV_VAR_V4_KEY . '=' . self::ECS_ENV_VAR_V4_VAL);

        $mockData = $this->createMock(DataProvider::class);
        $mockData->method('getHostName')->willReturn(self::HOST_NAME);

        $detector = new Detector($mockData);

        $invalidCgroups = [self::INVALID_CGROUP_LENGTH, self::INVALID_CGROUP_EMPTY, self::INVALID_CGROUP_VALUES];

        foreach ($invalidCgroups as $invalidCgroup) {
            $mockData->method('getCgroupData')->willReturn($invalidCgroup);

            $this->assertEquals(ResourceInfo::create(
                Attributes::create(
                    [
                        ResourceAttributes::CONTAINER_NAME => self::HOST_NAME,
                    ]
                )
            ), $detector->getResource());
        }

        //unset environment variable
        putenv(self::ECS_ENV_VAR_V4_KEY);
    }

    /**
     * @test
     */
    public function TestReturnOnlyContainerIdWithoutHostname()
    {
        putenv(self::ECS_ENV_VAR_V4_KEY . '=' . self::ECS_ENV_VAR_V4_VAL);

        $mockData = $this->createMock(DataProvider::class);

        $mockData->method('getCgroupData')->willReturn(self::VALID_CGROUP_DATA);
        $mockData->method('getHostName')->willReturn(null);

        $detector = new Detector($mockData);

        $this->assertEquals(ResourceInfo::create(
            Attributes::create(
                [
                    ResourceAttributes::CONTAINER_ID => self::EXTRACTED_CONTAINER_ID,
                ]
            )
        ), $detector->getResource());

        //unset environment variable
        putenv(self::ECS_ENV_VAR_V4_KEY);
    }

    /**
     * @test
     */
    public function TestReturnEmptyResourceInvalidContainerIdAndHostname()
    {
        // Set environment variable
        putenv(self::ECS_ENV_VAR_V4_KEY . '=' . self::ECS_ENV_VAR_V4_VAL);

        $mockData = $this->createMock(DataProvider::class);

        $mockData->method('getCgroupData')->willReturn(self::INVALID_CGROUP_LENGTH);
        $mockData->method('getHostName')->willReturn(null);

        $detector = new Detector($mockData);

        $this->assertEquals(ResourceInfoFactory::emptyResource(), $detector->getResource());

        // Unset environment variable
        putenv(self::ECS_ENV_VAR_V4_KEY);
    }
}
