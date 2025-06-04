<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Resource\Detector\DigitalOcean;

use Exception;
use Http\Discovery\Psr18Client;
use Nyholm\Psr7\Stream;
use OpenTelemetry\SDK\Common\Adapter\HttpDiscovery\MessageFactoryResolver;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Http\Psr\Client\Discovery;
use OpenTelemetry\SDK\Common\Http\Psr\Client\Discovery\DiscoveryInterface;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SemConv\ResourceAttributes;
use OpenTelemetry\SemConv\ResourceAttributeValues;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Zalas\PHPUnit\Globals\Attribute\Env;

function file_get_contents(string $filename)
{
    if (($_ENV['FAIL_IO'] ?? 'false') === 'true') {
        return false;
    }
    if (str_starts_with($filename, '/sys/devices/virtual/dmi/id')) {
        return match (substr($filename, 28)) {
            'sys_vendor' => (($_ENV['WRONG_VENDOR'] ?? 'false') === 'true') ? 'AWS' : 'DigitalOcean',
            'product_family' => 'DigitalOcean_Droplet',
            'board_asset_tag' => '10000000',
            default => 'unknown'
        };
    }

    return \file_get_contents($filename);
}

function gethostname()
{
    return 'test-server';
}

#[CoversClass(DigitalOceanDetector::class)]
class DigitalOceanDetectorTest extends TestCase
{
    private mixed $errorLog;

    public function setUp(): void
    {
        $this->errorLog = tmpfile();
        /** @psalm-suppress PossiblyFalseArgument */
        ini_set('error_log', stream_get_meta_data($this->errorLog)['uri']);

        $responseFactory = MessageFactoryResolver::create()->resolveResponseFactory();
        $client = $this->createStub(Psr18Client::class);
        $client->method('sendRequest')->willReturnCallback(function (RequestInterface $request) use ($responseFactory) {
            if ((string) $request->getUri() === 'http://169.254.169.254/metadata/v1.json') {
                if (($_ENV['FAIL_METADATA'] ?? 'false') === 'true') {
                    throw new Exception();
                }

                /** @psalm-suppress PossiblyFalseArgument */
                return $responseFactory->createResponse()->withBody(
                    Stream::create(file_get_contents(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'mock-metadata.json'))
                );
            } elseif (
                $request->getHeader('Authorization') === ['Bearer scoped-for-account'] &&
                (string) $request->getUri() === 'https://api.digitalocean.com/v2/account'
            ) {
                /** @psalm-suppress PossiblyFalseArgument */
                return $responseFactory->createResponse()->withBody(
                    Stream::create(file_get_contents(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'mock-account.json'))
                );
            } elseif (
                $request->getHeader('Authorization') === ['Bearer scoped-for-droplet'] &&
                (string) $request->getUri() === 'https://api.digitalocean.com/v2/droplets/10000000'
            ) {
                /** @psalm-suppress PossiblyFalseArgument */
                return $responseFactory->createResponse()->withBody(
                    Stream::create(file_get_contents(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'mock-droplet.json'))
                );
            }

            return $responseFactory->createResponse(404);
        });
        $clientDiscoverer = $this->createStub(DiscoveryInterface::class);
        $clientDiscoverer->method('available')->willReturn(true);
        $clientDiscoverer->method('create')->willReturn($client);
        Discovery::setDiscoverers([$clientDiscoverer]);
    }

    public function test_droplet_attributes_with_no_authz()
    {
        $this->assertEquals(
            Attributes::create([
                ResourceAttributes::CLOUD_PLATFORM => 'digitalocean_droplet',
                ResourceAttributes::CLOUD_PROVIDER => 'digitalocean',
                ResourceAttributes::CLOUD_REGION => 'ams1',
                ResourceAttributes::CLOUD_RESOURCE_ID => '10000000',
                ResourceAttributes::HOST_ARCH => ResourceAttributeValues::HOST_ARCH_AMD64,
                ResourceAttributes::HOST_ID => '10000000',
                ResourceAttributes::HOST_NAME => 'test-server',
                ResourceAttributes::OS_TYPE => ResourceAttributeValues::OS_TYPE_LINUX,
            ]),
            (new DigitalOceanDetector())->getResource()->getAttributes()
        );
    }

    #[Env('DIGITALOCEAN_ACCESS_TOKEN', 'scoped-for-account')]
    public function test_droplet_attributes_with_account_only_authz()
    {
        $this->assertEquals(
            Attributes::create([
                ResourceAttributes::CLOUD_ACCOUNT_ID => 'BCF58B93-BF65-4203-9B63-A5F6FD1AF06D',
                ResourceAttributes::CLOUD_PLATFORM => 'digitalocean_droplet',
                ResourceAttributes::CLOUD_PROVIDER => 'digitalocean',
                ResourceAttributes::CLOUD_REGION => 'ams1',
                ResourceAttributes::CLOUD_RESOURCE_ID => '10000000',
                ResourceAttributes::HOST_ARCH => ResourceAttributeValues::HOST_ARCH_AMD64,
                ResourceAttributes::HOST_ID => '10000000',
                ResourceAttributes::HOST_NAME => 'test-server',
                ResourceAttributes::OS_TYPE => ResourceAttributeValues::OS_TYPE_LINUX,
            ]),
            (new DigitalOceanDetector())->getResource()->getAttributes()
        );
        /** @psalm-suppress PossiblyFalseArgument */
        $this->assertStringContainsString(
            'DigitalOcean Access Token found, but unable to get Droplet info',
            stream_get_contents($this->errorLog)
        );
    }

    #[Env('DIGITALOCEAN_ACCESS_TOKEN', 'scoped-for-droplet')]
    public function test_droplet_attributes_with_droplet_only_authz()
    {
        $this->assertEquals(
            Attributes::create([
                ResourceAttributes::CLOUD_PLATFORM => 'digitalocean_droplet',
                ResourceAttributes::CLOUD_PROVIDER => 'digitalocean',
                ResourceAttributes::CLOUD_REGION => 'ams1',
                ResourceAttributes::CLOUD_RESOURCE_ID => '10000000',
                ResourceAttributes::HOST_ARCH => ResourceAttributeValues::HOST_ARCH_AMD64,
                ResourceAttributes::HOST_ID => '10000000',
                ResourceAttributes::HOST_NAME => 'test-server',
                ResourceAttributes::OS_TYPE => ResourceAttributeValues::OS_TYPE_LINUX,
                ResourceAttributes::HOST_IMAGE_ID => '9999999',
                ResourceAttributes::HOST_IMAGE_NAME => 'Debian 11 (bullseye)',
                ResourceAttributes::HOST_TYPE => 's-1vcpu-1gb',
                ResourceAttributes::OS_NAME => 'Debian',
            ]),
            (new DigitalOceanDetector())->getResource()->getAttributes()
        );
        /** @psalm-suppress PossiblyFalseArgument */
        $this->assertStringContainsString(
            'DigitalOcean Access Token found, but unable to get account info',
            stream_get_contents($this->errorLog)
        );
    }

    #[Env('FAIL_METADATA', 'true')]
    public function test_no_droplet_attributes()
    {
        $this->assertEquals(
            ResourceInfoFactory::emptyResource(),
            (new DigitalOceanDetector())->getResource()
        );
        /** @psalm-suppress PossiblyFalseArgument */
        $this->assertStringContainsString(
            'Failed to detect DigitalOcean resource attributes',
            stream_get_contents($this->errorLog)
        );
    }

    #[Env('WRONG_VENDOR', 'true')]
    public function test_not_digitalocean_environment()
    {
        $this->assertEquals(
            ResourceInfoFactory::emptyResource(),
            (new DigitalOceanDetector())->getResource()
        );
        /** @psalm-suppress PossiblyFalseArgument */
        $this->assertStringContainsString(
            'DigitalOcean resource detector enabled in non-DigitalOcean environment',
            stream_get_contents($this->errorLog)
        );
    }

    #[Env('FAIL_IO', 'true')]
    public function test_not_linux()
    {
        $this->assertEquals(
            ResourceInfoFactory::emptyResource(),
            (new DigitalOceanDetector())->getResource()
        );
        /** @psalm-suppress PossiblyFalseArgument */
        $this->assertStringContainsString(
            'DigitalOcean resource detector enabled in non-DigitalOcean environment',
            stream_get_contents($this->errorLog)
        );
    }
}
