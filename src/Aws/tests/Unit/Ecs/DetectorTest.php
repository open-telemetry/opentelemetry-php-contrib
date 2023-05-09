<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Aws\Unit\Ecs;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use OpenTelemetry\API\Common\Log\LoggerHolder;
use OpenTelemetry\Aws\Ecs\DataProvider;
use OpenTelemetry\Aws\Ecs\Detector;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SemConv\ResourceAttributes;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

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
    
    private const CLOUD_PROVIDER     = 'aws';
    private const CLOUD_PLATFORM     = 'aws_ecs';

    public function setUp(): void
    {
        LoggerHolder::set(new NullLogger());
    }

    /**
     * @test
     */
    public function TestValidCgroupData()
    {
        putenv(self::ECS_ENV_VAR_V3_KEY . '=' . self::ECS_ENV_VAR_V3_VAL);

        $mockData = $this->createMock(DataProvider::class);
        $mockData->method('getCgroupData')->willReturn(self::VALID_CGROUP_DATA);
        $mockData->method('getHostName')->willReturn(self::HOST_NAME);

        $mockGuzzle = new MockHandler([]);
        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);
        $requestFactory = new HttpFactory();

        $detector = new Detector($mockData, $client, $requestFactory);

        $this->assertEquals(ResourceInfo::create(
            Attributes::create(
                [
                    ResourceAttributes::CONTAINER_NAME => self::HOST_NAME,
                    ResourceAttributes::CONTAINER_ID => self::EXTRACTED_CONTAINER_ID,
                    ResourceAttributes::CLOUD_PROVIDER => self::CLOUD_PROVIDER,
                    ResourceAttributes::CLOUD_PLATFORM => self::CLOUD_PLATFORM,
                ]
            )
        ), $detector->getResource());

        //unset environment variable
        putenv(self::ECS_ENV_VAR_V3_KEY);
    }

    /**
     * @test
     */
    public function TestFirstValidCgroupData()
    {
        putenv(self::ECS_ENV_VAR_V3_KEY . '=' . self::ECS_ENV_VAR_V3_VAL);

        $mockData = $this->createMock(DataProvider::class);
        $mockData->method('getCgroupData')->willReturn(self::MULTIVALID_CGROUP_DATA);
        $mockData->method('getHostName')->willReturn(self::HOST_NAME);

        $mockGuzzle = new MockHandler([]);
        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);
        $requestFactory = new HttpFactory();

        $detector = new Detector($mockData, $client, $requestFactory);

        $this->assertEquals(ResourceInfo::create(
            Attributes::create(
                [
                    ResourceAttributes::CONTAINER_NAME => self::HOST_NAME,
                    ResourceAttributes::CONTAINER_ID => self::EXTRACTED_CONTAINER_ID,
                    ResourceAttributes::CLOUD_PROVIDER => self::CLOUD_PROVIDER,
                    ResourceAttributes::CLOUD_PLATFORM => self::CLOUD_PLATFORM,
                ]
            )
        ), $detector->getResource());

        //unset environment variable
        putenv(self::ECS_ENV_VAR_V3_KEY);
    }

    /**
     * @test
     */
    public function TestIsRunningOnEcsReturnsEmpty()
    {
        $mockData = $this->createMock(DataProvider::class);
        $mockData->method('getCgroupData')->willReturn(self::MULTIVALID_CGROUP_DATA);
        $mockData->method('getHostName')->willReturn(self::HOST_NAME);

        $mockGuzzle = new MockHandler([]);
        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);
        $requestFactory = new HttpFactory();

        $detector = new Detector($mockData, $client, $requestFactory);

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

        $mockGuzzle = new MockHandler([]);
        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);
        $requestFactory = new HttpFactory();

        $detector = new Detector($mockData, $client, $requestFactory);

        $this->assertEquals(ResourceInfo::create(
            Attributes::create(
                [
                    ResourceAttributes::CONTAINER_NAME => self::HOST_NAME,
                    ResourceAttributes::CLOUD_PROVIDER => self::CLOUD_PROVIDER,
                    ResourceAttributes::CLOUD_PLATFORM => self::CLOUD_PLATFORM,
                ]
            )
        ), $detector->getResource());

        //unset environment variable
        putenv(self::ECS_ENV_VAR_V3_KEY);
    }

    /**
     * @test
     */
    public function TestReturnOnlyHostnameWithInvalidCgroupFile()
    {
        // Test other version (v3)
        putenv(self::ECS_ENV_VAR_V3_KEY . '=' . self::ECS_ENV_VAR_V4_KEY);

        $mockData = $this->createMock(DataProvider::class);
        $mockData->method('getHostName')->willReturn(self::HOST_NAME);

        $mockGuzzle = new MockHandler([]);
        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);
        $requestFactory = new HttpFactory();

        $detector = new Detector($mockData, $client, $requestFactory);

        $invalidCgroups = [self::INVALID_CGROUP_LENGTH, self::INVALID_CGROUP_EMPTY, self::INVALID_CGROUP_VALUES];

        foreach ($invalidCgroups as $invalidCgroup) {
            $mockData->method('getCgroupData')->willReturn($invalidCgroup);

            $this->assertEquals(ResourceInfo::create(
                Attributes::create(
                    [
                        ResourceAttributes::CONTAINER_NAME => self::HOST_NAME,
                        ResourceAttributes::CLOUD_PROVIDER => self::CLOUD_PROVIDER,
                        ResourceAttributes::CLOUD_PLATFORM => self::CLOUD_PLATFORM,
                        ]
                )
            ), $detector->getResource());
        }

        //unset environment variable
        putenv(self::ECS_ENV_VAR_V3_KEY);
    }

    /**
     * @test
     */
    public function TestReturnOnlyContainerIdWithoutHostname()
    {
        putenv(self::ECS_ENV_VAR_V3_KEY . '=' . self::ECS_ENV_VAR_V3_VAL);

        $mockData = $this->createMock(DataProvider::class);
        $mockData->method('getCgroupData')->willReturn(self::VALID_CGROUP_DATA);
        $mockData->method('getHostName')->willReturn(null);

        $mockGuzzle = new MockHandler([]);
        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);
        $requestFactory = new HttpFactory();

        $detector = new Detector($mockData, $client, $requestFactory);

        $this->assertEquals(ResourceInfo::create(
            Attributes::create(
                [
                    ResourceAttributes::CONTAINER_ID => self::EXTRACTED_CONTAINER_ID,
                    ResourceAttributes::CLOUD_PROVIDER => self::CLOUD_PROVIDER,
                    ResourceAttributes::CLOUD_PLATFORM => self::CLOUD_PLATFORM,
                ]
            )
        ), $detector->getResource());

        //unset environment variable
        putenv(self::ECS_ENV_VAR_V3_KEY);
    }

    /**
     * @test
     */
    public function TestReturnEmptyResourceInvalidContainerIdAndHostname()
    {
        // Set environment variable
        putenv(self::ECS_ENV_VAR_V3_KEY . '=' . self::ECS_ENV_VAR_V3_VAL);

        $mockData = $this->createMock(DataProvider::class);
        $mockData->method('getCgroupData')->willReturn(self::INVALID_CGROUP_LENGTH);
        $mockData->method('getHostName')->willReturn(null);

        $mockGuzzle = new MockHandler([]);
        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);
        $requestFactory = new HttpFactory();

        $detector = new Detector($mockData, $client, $requestFactory);

        $this->assertEquals(ResourceInfo::create(
            Attributes::create(
                [
                    ResourceAttributes::CLOUD_PROVIDER => self::CLOUD_PROVIDER,
                    ResourceAttributes::CLOUD_PLATFORM => self::CLOUD_PLATFORM,
                ]
            )
        ), $detector->getResource());

        // Unset environment variable
        putenv(self::ECS_ENV_VAR_V3_KEY);
    }

    /**
     * @test
     */
    public function TestV4EndpointFails()
    {
        putenv(self::ECS_ENV_VAR_V4_KEY . '=' . self::ECS_ENV_VAR_V4_VAL);

        $mockData = $this->createMock(DataProvider::class);
        $mockData->method('getCgroupData')->willReturn(self::INVALID_CGROUP_LENGTH);
        $mockData->method('getHostName')->willReturn(null);

        $mockGuzzle = new MockHandler([
            new Response(500, ['Content-Type' => 'application/json'], '{"message":"cuz I have a baad daaay"}'),
        ]);
        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);
        $requestFactory = new HttpFactory();

        $detector = new Detector($mockData, $client, $requestFactory);

        $this->assertEquals(ResourceInfo::create(
            Attributes::create(
                [
                    ResourceAttributes::CLOUD_PROVIDER => self::CLOUD_PROVIDER,
                    ResourceAttributes::CLOUD_PLATFORM => self::CLOUD_PLATFORM,
                ]
            )
        ), $detector->getResource());

        putenv(self::ECS_ENV_VAR_V4_KEY);
    }

    /**
     * @test
     */
    public function TestV4ResourceLaunchTypeEc2()
    {
        putenv(self::ECS_ENV_VAR_V4_KEY . '=' . self::ECS_ENV_VAR_V4_VAL);

        $mockData = $this->createMock(DataProvider::class);
        $mockData->method('getCgroupData')->willReturn(self::INVALID_CGROUP_LENGTH);
        $mockData->method('getHostName')->willReturn(null);

        $mockGuzzle = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $this->getResponseBodyFor('metadatav4-response-container-ec2.json')),
            new Response(200, ['Content-Type' => 'application/json'], $this->getResponseBodyFor('metadatav4-response-task-ec2.json')),
        ]);
        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);
        $requestFactory = new HttpFactory();

        $detector = new Detector($mockData, $client, $requestFactory);

        $this->assertEquals(ResourceInfo::create(
            Attributes::create(
                [
                    ResourceAttributes::CLOUD_PROVIDER => self::CLOUD_PROVIDER,
                    ResourceAttributes::CLOUD_PLATFORM => self::CLOUD_PLATFORM,
                    ResourceAttributes::AWS_ECS_CONTAINER_ARN => 'arn:aws:ecs:us-west-2:111122223333:container/0206b271-b33f-47ab-86c6-a0ba208a70a9',
                    ResourceAttributes::AWS_ECS_CLUSTER_ARN => 'arn:aws:ecs:us-west-2:111122223333:cluster/default',
                    ResourceAttributes::AWS_ECS_LAUNCHTYPE => 'ec2',
                    ResourceAttributes::AWS_ECS_TASK_ARN => 'arn:aws:ecs:us-west-2:111122223333:task/default/158d1c8083dd49d6b527399fd6414f5c',
                    ResourceAttributes::AWS_ECS_TASK_FAMILY => 'curltest',
                    ResourceAttributes::AWS_ECS_TASK_REVISION => '26',
                    ResourceAttributes::AWS_LOG_GROUP_NAMES => ['/ecs/metadata'],
                    ResourceAttributes::AWS_LOG_GROUP_ARNS => ['arn:aws:logs:us-west-2:111122223333:log-group:/ecs/metadata'],
                    ResourceAttributes::AWS_LOG_STREAM_NAMES => ['ecs/curl/8f03e41243824aea923aca126495f665'],
                    ResourceAttributes::AWS_LOG_STREAM_ARNS => ['arn:aws:logs:us-west-2:111122223333:log-group:/ecs/metadata:log-stream:ecs/curl/8f03e41243824aea923aca126495f665'],
                ]
            )
        ), $detector->getResource());

        putenv(self::ECS_ENV_VAR_V4_KEY);
    }

    /**
     * @test
     */
    public function TestV4ResourceLaunchTypeFargate()
    {
        putenv(self::ECS_ENV_VAR_V4_KEY . '=' . self::ECS_ENV_VAR_V4_VAL);

        $mockData = $this->createMock(DataProvider::class);
        $mockData->method('getCgroupData')->willReturn(self::INVALID_CGROUP_LENGTH);
        $mockData->method('getHostName')->willReturn(null);

        $mockGuzzle = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $this->getResponseBodyFor('metadatav4-response-container-fargate.json')),
            new Response(200, ['Content-Type' => 'application/json'], $this->getResponseBodyFor('metadatav4-response-task-fargate.json')),
        ]);
        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);
        $requestFactory = new HttpFactory();

        $detector = new Detector($mockData, $client, $requestFactory);

        $this->assertEquals(ResourceInfo::create(
            Attributes::create(
                [
                    ResourceAttributes::CLOUD_PROVIDER => self::CLOUD_PROVIDER,
                    ResourceAttributes::CLOUD_PLATFORM => self::CLOUD_PLATFORM,
                    ResourceAttributes::AWS_ECS_CONTAINER_ARN => 'arn:aws:ecs:us-west-2:111122223333:container/05966557-f16c-49cb-9352-24b3a0dcd0e1',
                    ResourceAttributes::AWS_ECS_CLUSTER_ARN => 'arn:aws:ecs:us-west-2:111122223333:cluster/default',
                    ResourceAttributes::AWS_ECS_LAUNCHTYPE => 'fargate',
                    ResourceAttributes::AWS_ECS_TASK_ARN => 'arn:aws:ecs:us-west-2:111122223333:task/default/e9028f8d5d8e4f258373e7b93ce9a3c3',
                    ResourceAttributes::AWS_ECS_TASK_FAMILY => 'curltest',
                    ResourceAttributes::AWS_ECS_TASK_REVISION => '3',
                    ResourceAttributes::AWS_LOG_GROUP_NAMES => ['/ecs/containerlogs'],
                    ResourceAttributes::AWS_LOG_GROUP_ARNS => ['arn:aws:logs:us-west-2:111122223333:log-group:/ecs/containerlogs'],
                    ResourceAttributes::AWS_LOG_STREAM_NAMES => ['ecs/curl/cd189a933e5849daa93386466019ab50'],
                    ResourceAttributes::AWS_LOG_STREAM_ARNS => ['arn:aws:logs:us-west-2:111122223333:log-group:/ecs/containerlogs:log-stream:ecs/curl/cd189a933e5849daa93386466019ab50'],
                ]
            )
        ), $detector->getResource());

        putenv(self::ECS_ENV_VAR_V4_KEY);
    }

    /**
     * @test
     */
    public function TestV4ResourceLogDriverFireLens()
    {
        putenv(self::ECS_ENV_VAR_V4_KEY . '=' . self::ECS_ENV_VAR_V4_VAL);

        $mockData = $this->createMock(DataProvider::class);
        $mockData->method('getCgroupData')->willReturn(self::INVALID_CGROUP_LENGTH);
        $mockData->method('getHostName')->willReturn(null);

        $mockGuzzle = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $this->getResponseBodyFor('metadatav4-response-container-fargate-logsfirelens.json')),
            new Response(200, ['Content-Type' => 'application/json'], $this->getResponseBodyFor('metadatav4-response-task-fargate-logsfirelens.json')),
        ]);
        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);
        $requestFactory = new HttpFactory();

        $detector = new Detector($mockData, $client, $requestFactory);

        $this->assertEquals(ResourceInfo::create(
            Attributes::create(
                [
                    ResourceAttributes::CLOUD_PROVIDER => self::CLOUD_PROVIDER,
                    ResourceAttributes::CLOUD_PLATFORM => self::CLOUD_PLATFORM,
                    ResourceAttributes::AWS_ECS_CONTAINER_ARN => 'arn:aws:ecs:us-west-2:111122223333:container/05966557-f16c-49cb-9352-24b3a0dcd0e1',
                    ResourceAttributes::AWS_ECS_CLUSTER_ARN => 'arn:aws:ecs:us-west-2:111122223333:cluster/default',
                    ResourceAttributes::AWS_ECS_LAUNCHTYPE => 'fargate',
                    ResourceAttributes::AWS_ECS_TASK_ARN => 'arn:aws:ecs:us-west-2:111122223333:task/default/e9028f8d5d8e4f258373e7b93ce9a3c3',
                    ResourceAttributes::AWS_ECS_TASK_FAMILY => 'curltest',
                    ResourceAttributes::AWS_ECS_TASK_REVISION => '3',
                ]
            )
        ), $detector->getResource());

        putenv(self::ECS_ENV_VAR_V4_KEY);
    }

    private function getResponseBodyFor($filename)
    {
        return file_get_contents(dirname(__FILE__) . DIRECTORY_SEPARATOR . $filename);
    }
}
