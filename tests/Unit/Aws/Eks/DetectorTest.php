<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Aws\Eks;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use OpenTelemetry\Aws\Eks\DataProvider;
use OpenTelemetry\Aws\Eks\Detector;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Attributes;
use OpenTelemetry\SemConv\ResourceAttributes;
use PHPUnit\Framework\TestCase;

class DetectorTest extends TestCase
{
    private const VALID_CGROUP_DATA = ['abcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklm', '', 'abcdefghijk'];
    private const INVALID_CGROUP_LENGTH = ['abcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijkl', 'abcdefghijk',' '];
    private const INVALID_CGROUP_EMPTY = [];
    private const INVALID_CGROUP_VALUES = ['','',''];

    private const MOCK_VALID_CLUSTER_RESPONSE = '{"data":{"cluster.name":"my-cluster"}}';
    private const MOCK_EMPTY_CLUSTER_NAME = '{"data": "empty"}';
    private const MOCK_EMPTY_CLUSTER_DATA = '{"test": "empty"}';
    private const MOCK_EMPTY_BODY = '';
    private const MOCK_AWS_AUTH = 'my-auth';
    private const MOCK_K8S_TOKEN = 'Bearer 31ada4fd-adec-460c-809a-9e56ceb75269';

    private const EXTRACTED_CONTAINER_ID = 'bcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklm';
    private const EXTRACTED_CLUSTER_NAME = 'my-cluster';

    /**
     * @test
     */
    public function TestValidKubernetes()
    {
        $mockData = $this->createMock(DataProvider::class);

        $mockData->method('getCgroupData')->willReturn(self::VALID_CGROUP_DATA);
        $mockData->method('getK8sHeader')->willReturn(self::MOCK_K8S_TOKEN);
        $mockData->method('isK8s')->willReturn(self::MOCK_AWS_AUTH);

        $mockGuzzle = new MockHandler([
            new Response(200, ['Foo' => 'Bar'], self::MOCK_AWS_AUTH),
            new Response(200, ['Foo' => 'Bar'], self::MOCK_VALID_CLUSTER_RESPONSE),

        ]);
        
        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);

        $detector = new Detector($mockData, $client);

        $this->assertEquals(ResourceInfo::create(
            new Attributes(
                [
                    ResourceAttributes::CONTAINER_ID => self::EXTRACTED_CONTAINER_ID,
                    ResourceAttributes::K8S_CLUSTER_NAME => self::EXTRACTED_CLUSTER_NAME,
                ]
            )
        ), $detector->detect());
    }
    
    /**
     * @test
     */
    public function TestValidClusterNameInvalidContainerId()
    {
        $mockData = $this->createMock(DataProvider::class);

        $mockData->method('getCgroupData')->willReturn(self::INVALID_CGROUP_VALUES);
        $mockData->method('getK8sHeader')->willReturn(self::MOCK_K8S_TOKEN);
        $mockData->method('isK8s')->willReturn(self::MOCK_AWS_AUTH);

        $mockGuzzle = new MockHandler([
            new Response(200, ['Foo' => 'Bar'], self::MOCK_AWS_AUTH),
            new Response(200, ['Foo' => 'Bar'], self::MOCK_VALID_CLUSTER_RESPONSE),

        ]);
        
        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);

        $detector = new Detector($mockData, $client);

        echo PHP_EOL . '-------------------------';

        $this->assertEquals(ResourceInfo::create(
            new Attributes(
                [
                    ResourceAttributes::K8S_CLUSTER_NAME => self::EXTRACTED_CLUSTER_NAME,
                ]
            )
        ), $detector->detect());
    }

    /**
     * @test
     */
    public function TestValidClusterNameEmptyContainerId()
    {
        $mockData = $this->createMock(DataProvider::class);

        $mockData->method('getCgroupData')->willReturn(self::INVALID_CGROUP_EMPTY);
        $mockData->method('getK8sHeader')->willReturn(self::MOCK_K8S_TOKEN);
        $mockData->method('isK8s')->willReturn(self::MOCK_AWS_AUTH);

        $mockGuzzle = new MockHandler([
            new Response(200, ['Foo' => 'Bar'], self::MOCK_AWS_AUTH),
            new Response(200, ['Foo' => 'Bar'], self::MOCK_VALID_CLUSTER_RESPONSE),

        ]);
        
        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);

        $detector = new Detector($mockData, $client);

        $this->assertEquals(ResourceInfo::create(
            new Attributes(
                [
                    ResourceAttributes::K8S_CLUSTER_NAME => self::EXTRACTED_CLUSTER_NAME,
                ]
            )
        ), $detector->detect());
    }

    /**
     * @test
     */
    public function TestValidClusterNameShortContainerId()
    {
        $mockData = $this->createMock(DataProvider::class);

        $mockData->method('getCgroupData')->willReturn(self::INVALID_CGROUP_LENGTH);
        $mockData->method('getK8sHeader')->willReturn(self::MOCK_K8S_TOKEN);
        $mockData->method('isK8s')->willReturn(self::MOCK_AWS_AUTH);

        $mockGuzzle = new MockHandler([
            new Response(200, ['Foo' => 'Bar'], self::MOCK_AWS_AUTH),
            new Response(200, ['Foo' => 'Bar'], self::MOCK_VALID_CLUSTER_RESPONSE),

        ]);
        
        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);

        $detector = new Detector($mockData, $client);

        $this->assertEquals(ResourceInfo::create(
            new Attributes(
                [
                    ResourceAttributes::K8S_CLUSTER_NAME => self::EXTRACTED_CLUSTER_NAME,
                ]
            )
        ), $detector->detect());
    }

    /**
     * @test
     */
    public function TestValidContianerIdEmptyClusterName()
    {
        $mockData = $this->createMock(DataProvider::class);

        $mockData->method('getCgroupData')->willReturn(self::VALID_CGROUP_DATA);
        $mockData->method('getK8sHeader')->willReturn(self::MOCK_K8S_TOKEN);
        $mockData->method('isK8s')->willReturn(self::MOCK_AWS_AUTH);

        $mockGuzzle = new MockHandler([
            new Response(200, ['Foo' => 'Bar'], self::MOCK_AWS_AUTH),
            new Response(200, ['Foo' => 'Bar'], self::MOCK_EMPTY_CLUSTER_NAME),

        ]);
        
        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);

        $detector = new Detector($mockData, $client);

        $this->assertEquals(ResourceInfo::create(
            new Attributes(
                [
                    ResourceAttributes::CONTAINER_ID => self::EXTRACTED_CONTAINER_ID,
                ]
            )
        ), $detector->detect());
    }

    /**
     * @test
     */
    public function testValidContainerIdEmptyData()
    {
        $mockData = $this->createMock(DataProvider::class);

        $mockData->method('getCgroupData')->willReturn(self::VALID_CGROUP_DATA);
        $mockData->method('getK8sHeader')->willReturn(self::MOCK_K8S_TOKEN);
        $mockData->method('isK8s')->willReturn(self::MOCK_AWS_AUTH);

        $mockGuzzle = new MockHandler([
            new Response(200, ['Foo' => 'Bar'], self::MOCK_AWS_AUTH),
            new Response(200, ['Foo' => 'Bar'], self::MOCK_EMPTY_CLUSTER_DATA),

        ]);
        
        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);

        $detector = new Detector($mockData, $client);

        $this->assertEquals(ResourceInfo::create(
            new Attributes(
                [
                    ResourceAttributes::CONTAINER_ID => self::EXTRACTED_CONTAINER_ID,
                ]
            )
        ), $detector->detect());
    }

    /**
     * @test
     */
    public function TestValidContianerIdEmptyBody()
    {
        $mockData = $this->createMock(DataProvider::class);

        $mockData->method('getCgroupData')->willReturn(self::VALID_CGROUP_DATA);
        $mockData->method('getK8sHeader')->willReturn(self::MOCK_K8S_TOKEN);
        $mockData->method('isK8s')->willReturn(self::MOCK_AWS_AUTH);

        $mockGuzzle = new MockHandler([
            new Response(200, ['Foo' => 'Bar'], self::MOCK_AWS_AUTH),
            new Response(200, ['Foo' => 'Bar'], self::MOCK_EMPTY_BODY),

        ]);
        
        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);

        $detector = new Detector($mockData, $client);

        $this->assertEquals(ResourceInfo::create(
            new Attributes(
                [
                    ResourceAttributes::CONTAINER_ID => self::EXTRACTED_CONTAINER_ID,
                ]
            )
        ), $detector->detect());
    }

    /**
     * @test
     */
    public function TestInvalidBodyIsEks()
    {
        $mockData = $this->createMock(DataProvider::class);

        $mockData->method('getCgroupData')->willReturn(self::VALID_CGROUP_DATA);
        $mockData->method('getK8sHeader')->willReturn(self::MOCK_K8S_TOKEN);
        $mockData->method('isK8s')->willReturn(self::MOCK_AWS_AUTH);

        $mockGuzzle = new MockHandler([
            new Response(300, ['Foo' => 'Bar'], self::MOCK_EMPTY_BODY),
            new Response(200, ['Foo' => 'Bar'], self::MOCK_VALID_CLUSTER_RESPONSE),

        ]);
        
        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);

        $detector = new Detector($mockData, $client);

        $this->assertEquals(ResourceInfo::emptyResource(), $detector->detect());
    }

    /**
     * @test
     */
    public function TestInvalidResponseCode()
    {
        $mockData = $this->createMock(DataProvider::class);

        $mockData->method('getCgroupData')->willReturn(self::VALID_CGROUP_DATA);
        $mockData->method('getK8sHeader')->willReturn(self::MOCK_K8S_TOKEN);
        $mockData->method('isK8s')->willReturn(self::MOCK_AWS_AUTH);

        $mockGuzzle = new MockHandler([
            new Response(300, ['Foo' => 'Bar'], self::MOCK_AWS_AUTH),
            new Response(200, ['Foo' => 'Bar'], self::MOCK_VALID_CLUSTER_RESPONSE),

        ]);
        
        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);

        $detector = new Detector($mockData, $client);

        $this->assertEquals(ResourceInfo::emptyResource(), $detector->detect());
    }
    
    /**
     * @test
     */
    public function TestInvalidContainerIdAndClusterName()
    {
        $mockData = $this->createMock(DataProvider::class);

        $mockData->method('getCgroupData')->willReturn(self::INVALID_CGROUP_VALUES);
        $mockData->method('getK8sHeader')->willReturn(self::MOCK_K8S_TOKEN);
        $mockData->method('isK8s')->willReturn(self::MOCK_AWS_AUTH);

        $mockGuzzle = new MockHandler([
            new Response(200, ['Foo' => 'Bar'], self::MOCK_AWS_AUTH),
            new Response(200, ['Foo' => 'Bar'], self::MOCK_EMPTY_BODY),

        ]);
        
        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);

        $detector = new Detector($mockData, $client);

        $this->assertEquals(ResourceInfo::emptyResource(), $detector->detect());
    }

    /**
     * @test
     */
    public function TestInvalidisKubernetesFile()
    {
        $mockData = $this->createMock(DataProvider::class);

        $mockData->method('getCgroupData')->willReturn(self::INVALID_CGROUP_VALUES);
        $mockData->method('getK8sHeader')->willReturn(self::MOCK_K8S_TOKEN);
        $mockData->method('isK8s')->willReturn(false);

        $mockGuzzle = new MockHandler([
            new Response(200, ['Foo' => 'Bar'], self::MOCK_AWS_AUTH),
        ]);
        
        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);

        $detector = new Detector($mockData, $client);

        $this->assertEquals(ResourceInfo::emptyResource(), $detector->detect());
    }
}
