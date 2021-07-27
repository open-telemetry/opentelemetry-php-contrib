<?php

declare(strict_types=1);

use Detectors\Aws\EcsDetector;
use Detectors\Aws\EcsProcessDataProvider;
use OpenTelemetry\Sdk\Resource\ResourceConstants;
use OpenTelemetry\Sdk\Resource\ResourceInfo;
use OpenTelemetry\Sdk\Trace\Attributes;
use PHPUnit\Framework\TestCase;

class EcsDetectorTest extends TestCase
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

        $mockData = $this->createMock(EcsProcessDataProvider::class);

        $mockData->method('getCgroupData')->willReturn(self::VALID_CGROUP_DATA);
        $mockData->method('getHostName')->willReturn(self::HOST_NAME);

        $detector = new EcsDetector($mockData);

        $this->assertEquals(ResourceInfo::create(
            new Attributes(
                [
                    ResourceConstants::CONTAINER_NAME => self::HOST_NAME,
                    ResourceConstants::CONTAINER_ID => self::EXTRACTED_CONTAINER_ID,
                ]
            )
        ), $detector->detect());

        //unset environment variable
        putenv(self::ECS_ENV_VAR_V4_KEY);
    }
    
    /**
     * @test
     */
    public function TestFirstValidCgroupData()
    {
        putenv(self::ECS_ENV_VAR_V4_KEY . '=' . self::ECS_ENV_VAR_V4_VAL);

        $mockData = $this->createMock(EcsProcessDataProvider::class);

        $mockData->method('getCgroupData')->willReturn(self::MULTIVALID_CGROUP_DATA);
        $mockData->method('getHostName')->willReturn(self::HOST_NAME);

        $detector = new EcsDetector($mockData);

        $this->assertEquals(ResourceInfo::create(
            new Attributes(
                [
                    ResourceConstants::CONTAINER_NAME => self::HOST_NAME,
                    ResourceConstants::CONTAINER_ID => self::EXTRACTED_CONTAINER_ID,
                ]
            )
        ), $detector->detect());

        //unset environment variable
        putenv(self::ECS_ENV_VAR_V4_KEY);
    }

    /**
     * @test
     */
    public function TestIsRunningOnEcsReturnsEmpty()
    {
        $mockData = $this->createMock(EcsProcessDataProvider::class);

        $mockData->method('getCgroupData')->willReturn(self::MULTIVALID_CGROUP_DATA);
        $mockData->method('getHostName')->willReturn(self::HOST_NAME);

        $detector = new EcsDetector($mockData);

        $this->assertEquals(ResourceInfo::emptyResource(), $detector->detect());
    }

    /**
     * @test
     */
    public function TestReturnOnlyHostnameWithoutCgroupFile()
    {
        // Test other version (v3)
        putenv(self::ECS_ENV_VAR_V3_KEY . '=' . self::ECS_ENV_VAR_V3_VAL);

        $mockData = $this->createMock(EcsProcessDataProvider::class);

        $mockData->method('getCgroupData')->willReturn(false);
        $mockData->method('getHostName')->willReturn(self::HOST_NAME);

        $detector = new EcsDetector($mockData);

        $this->assertEquals(ResourceInfo::create(
            new Attributes(
                [
                    ResourceConstants::CONTAINER_NAME => self::HOST_NAME,
                ]
            )
        ), $detector->detect());

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

        $mockData = $this->createMock(EcsProcessDataProvider::class);
        $mockData->method('getHostName')->willReturn(self::HOST_NAME);

        $detector = new EcsDetector($mockData);
      
        $invalidCgroups = [self::INVALID_CGROUP_LENGTH, self::INVALID_CGROUP_EMPTY, self::INVALID_CGROUP_VALUES];

        foreach ($invalidCgroups as $invalidCgroup) {
            $mockData->method('getCgroupData')->willReturn($invalidCgroup);

            $this->assertEquals(ResourceInfo::create(
                new Attributes(
                    [
                        ResourceConstants::CONTAINER_NAME => self::HOST_NAME,
                    ]
                )
            ), $detector->detect());
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

        $mockData = $this->createMock(EcsProcessDataProvider::class);

        $mockData->method('getCgroupData')->willReturn(self::VALID_CGROUP_DATA);
        $mockData->method('getHostName')->willReturn(null);

        $detector = new EcsDetector($mockData);

        $this->assertEquals(ResourceInfo::create(
            new Attributes(
                [
                    ResourceConstants::CONTAINER_ID => self::EXTRACTED_CONTAINER_ID,
                ]
            )
        ), $detector->detect());

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

        $mockData = $this->createMock(EcsProcessDataProvider::class);

        $mockData->method('getCgroupData')->willReturn(self::INVALID_CGROUP_LENGTH);
        $mockData->method('getHostName')->willReturn(null);
        
        $detector = new EcsDetector($mockData);

        $this->assertEquals(ResourceInfo::emptyResource(), $detector->detect());

        // Unset environment variable
        putenv(self::ECS_ENV_VAR_V4_KEY);
    }
}
