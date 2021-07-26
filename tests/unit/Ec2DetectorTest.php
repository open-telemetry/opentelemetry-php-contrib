<?php

declare(strict_types=1);

use Detectors\Aws\Ec2Detector;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use OpenTelemetry\Sdk\Resource\ResourceConstants;
use OpenTelemetry\Sdk\Resource\ResourceInfo;
use OpenTelemetry\Sdk\Trace\Attributes;
use PHPUnit\Framework\TestCase;

class Ec2DetectorTest extends TestCase
{
    private const MOCK_TOKEN_RESPONSE = 'my-token';
    private const MOCK_HOSTNAME = 'my-hostname';
    private const MOCK_IDENTITY = '{
        "instanceId": "my-instance-id",
        "instanceType": "my-instance-type",
        "accountId": "my-account-id",
        "region": "my-region",
        "availabilityZone": "my-zone",
        "imageId": "image-id"
      }';

    private const MOCK_IDENTITY_INCOMPLETE = '{
    "instanceId": "my-instance-id",
    "instanceType": "my-instance-type",
    "availabilityZone": "my-zone",
    "imageId": "image-id"
    }';

    private const HOST_ID = 'my-instance-id';
    private const CLOUD_ZONE = 'my-zone';
    private const HOST_TYPE = 'my-instance-type';
    private const HOST_IMAGE_ID = 'image-id';
    private const CLOUD_ACCOUNT_ID = 'my-account-id';
    private const CLOUD_REGION = 'my-region';
    private const CLOUD_PROVIDER = 'aws';

    /**
     * @test
     */
    public function TestValidEc2()
    {
        $mockGuzzle = new MockHandler([
            //Fetch token response
            new Response(200, ['Foo' => 'Bar'], self::MOCK_TOKEN_RESPONSE),
            // Fetch hostName response
            new Response(200, ['Foo' => 'Bar'], self::MOCK_HOSTNAME),
            // Fetch identities reponse
            new Response(200, ['Foo' => 'Bar'], self::MOCK_IDENTITY),
        ]);

        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);

        $detector = new Ec2Detector($client);

        $this->assertEquals(ResourceInfo::create(
            new Attributes(
                [
                    ResourceConstants::HOST_ID => self::HOST_ID,
                    ResourceConstants::CLOUD_ZONE => self::CLOUD_ZONE,
                    ResourceConstants::HOST_TYPE => self::HOST_TYPE,
                    ResourceConstants::HOST_IMAGE_ID => self::HOST_IMAGE_ID,
                    ResourceConstants::CLOUD_ACCOUNT_ID => self::CLOUD_ACCOUNT_ID,
                    ResourceConstants::CLOUD_REGION => self::CLOUD_REGION,
                    ResourceConstants::HOST_HOSTNAME => self::MOCK_HOSTNAME,
                    ResourceConstants::CLOUD_PROVIDER => self::CLOUD_PROVIDER,
                ]
            )
        ), $detector->detect());
    }

    /**
     * @test
     */
    public function TestInvalidTokenBody()
    {
        $mockGuzzle = new MockHandler([
            //Fetch token response
            new Response(200, ['Foo' => 'Bar']),
            // Fetch hostName response
            new Response(200, ['Foo' => 'Bar'], self::MOCK_HOSTNAME),
            // Fetch identities reponse
            new Response(200, ['Foo' => 'Bar'], self::MOCK_IDENTITY),
        ]);

        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);

        $detector = new Ec2Detector($client);

        $this->assertEquals(ResourceInfo::emptyResource(), $detector->detect());
    }

    /**
     * @test
     */
    public function TestInvalidTokenResponseCode()
    {
        $mockGuzzle = new MockHandler([
            //Fetch token response
            new Response(404, ['Foo' => 'Bar'], self::MOCK_TOKEN_RESPONSE),
            // Fetch hostName response
            new Response(200, ['Foo' => 'Bar'], self::MOCK_HOSTNAME),
            // Fetch identities reponse
            new Response(200, ['Foo' => 'Bar'], self::MOCK_IDENTITY),
        ]);

        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);

        $detector = new Ec2Detector($client);

        $this->assertEquals(ResourceInfo::emptyResource(), $detector->detect());
    }

    /**
     * @test
     */
    public function TestInvalidHostName()
    {
        $mockGuzzle = new MockHandler([
            //Fetch token response
            new Response(200, ['Foo' => 'Bar'], self::MOCK_TOKEN_RESPONSE),
            // Fetch hostName response
            new Response(200, ['Foo' => 'Bar']),
            // Fetch identities reponse
            new Response(200, ['Foo' => 'Bar'], self::MOCK_IDENTITY),
        ]);

        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);

        $detector = new Ec2Detector($client);

        $this->assertEquals(ResourceInfo::create(
            new Attributes(
                [
                    ResourceConstants::HOST_ID => self::HOST_ID,
                    ResourceConstants::CLOUD_ZONE => self::CLOUD_ZONE,
                    ResourceConstants::HOST_TYPE => self::HOST_TYPE,
                    ResourceConstants::HOST_IMAGE_ID => self::HOST_IMAGE_ID,
                    ResourceConstants::CLOUD_ACCOUNT_ID => self::CLOUD_ACCOUNT_ID,
                    ResourceConstants::CLOUD_REGION => self::CLOUD_REGION,
                    ResourceConstants::CLOUD_PROVIDER => self::CLOUD_PROVIDER
                ]
            )
        ), $detector->detect());
    }

    /**
     * @test
     */
    public function TestInvalidIdentities()
    {
        $mockGuzzle = new MockHandler([
            //Fetch token response
            new Response(200, ['Foo' => 'Bar'], self::MOCK_TOKEN_RESPONSE),
            // Fetch hostName response
            new Response(200, ['Foo' => 'Bar'], self::MOCK_HOSTNAME),
            // Fetch identities reponse
            new Response(200, ['Foo' => 'Bar']),
        ]);

        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);

        $detector = new Ec2Detector($client);

        $this->assertEquals(ResourceInfo::emptyResource(), $detector->detect());
    }

    /**
     * @test
     */
    public function TestInvalidIncompleteIdentities()
    {
        $mockGuzzle = new MockHandler([
            //Fetch token response
            new Response(200, ['Foo' => 'Bar'], self::MOCK_TOKEN_RESPONSE),
            // Fetch hostName response
            new Response(200, ['Foo' => 'Bar'], self::MOCK_HOSTNAME),
            // Fetch identities reponse
            new Response(200, ['Foo' => 'Bar'], self::MOCK_IDENTITY_INCOMPLETE),
        ]);

        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);

        $detector = new Ec2Detector($client);

        $this->assertEquals(ResourceInfo::create(
            new Attributes(
                [
                    ResourceConstants::HOST_ID => self::HOST_ID,
                    ResourceConstants::CLOUD_ZONE => self::CLOUD_ZONE,
                    ResourceConstants::HOST_TYPE => self::HOST_TYPE,
                    ResourceConstants::HOST_IMAGE_ID => self::HOST_IMAGE_ID,
                    ResourceConstants::HOST_HOSTNAME => self::MOCK_HOSTNAME,
                    ResourceConstants::CLOUD_PROVIDER => self::CLOUD_PROVIDER
                ]
            )
        ), $detector->detect());
    }
}
