<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Resource\Detector\DigitalOcean;

use Http\Discovery\Psr18Client;
use Nyholm\Psr7\Stream;
use OpenTelemetry\SDK\Common\Adapter\HttpDiscovery\MessageFactoryResolver;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Http\Psr\Client\Discovery;
use OpenTelemetry\SDK\Common\Http\Psr\Client\Discovery\DiscoveryInterface;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SemConv\ResourceAttributes;
use OpenTelemetry\SemConv\ResourceAttributeValues;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Zalas\PHPUnit\Globals\Attribute\Server;

function gethostname()
{
    return 'test-server';
}

#[CoversClass(DigitalOceanDetector::class)]
class DigitalOceanDetectorTest extends TestCase
{
    private mixed $errorLog;
    private vfsStreamDirectory $vfs;

    public function setUp(): void
    {
        /** mock sysfs with DigitalOcean SMBIOS pointers */
        $this->vfs = vfsStream::setup('/', structure: [
            'sys' => [
                'devices' => [
                    'virtual' => [
                        'dmi' => [
                            'id' => [
                                'board_asset_tag' => '10000000',
                                'product_family' => 'DigitalOcean_Droplet',
                                'sys_vendor' => 'DigitalOcean',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        /** mock stdout for `error_log` */
        $this->errorLog = tmpfile();
        /** @psalm-suppress PossiblyFalseArgument */
        ini_set('error_log', stream_get_meta_data($this->errorLog)['uri']);

        /** mock HTTP client and DigitalOcean API responses */
        $responseFactory = MessageFactoryResolver::create()->resolveResponseFactory();
        $client = $this->createStub(Psr18Client::class);
        $client->method('sendRequest')->willReturnCallback(function (RequestInterface $request) use ($responseFactory) {
            if ((string) $request->getUri() === 'http://169.254.169.254/metadata/v1.json') {
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
            (new DigitalOceanDetector($this->vfs->url()))->getResource()->getAttributes()
        );
    }

    #[Server('DIGITALOCEAN_ACCESS_TOKEN', 'scoped-for-account')]
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
            (new DigitalOceanDetector($this->vfs->url()))->getResource()->getAttributes()
        );
        /** @psalm-suppress PossiblyFalseArgument */
        $this->assertStringContainsString(
            'DigitalOcean Access Token found; setting DigitalOcean account ID in resource attributes',
            stream_get_contents($this->errorLog)
        );
    }

    #[Server('DIGITALOCEAN_ACCESS_TOKEN', 'scoped-for-droplet')]
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
            (new DigitalOceanDetector($this->vfs->url()))->getResource()->getAttributes()
        );
        /** @psalm-suppress PossiblyFalseArgument */
        $this->assertStringContainsString(
            'DigitalOcean Access Token found; setting additional Droplet info in resource attributes',
            stream_get_contents($this->errorLog)
        );
    }

    public function test_no_droplet_attributes()
    {
        /** mock HTTP client and with failing response */
        $client = $this->createStub(Psr18Client::class);
        $client->method('sendRequest')->willReturnCallback(function () {
            $responseFactory = MessageFactoryResolver::create()->resolveResponseFactory();

            return $responseFactory->createResponse(500);
        });
        $clientDiscoverer = $this->createStub(DiscoveryInterface::class);
        $clientDiscoverer->method('available')->willReturn(true);
        $clientDiscoverer->method('create')->willReturn($client);
        Discovery::setDiscoverers([$clientDiscoverer]);

        $this->assertEquals(
            ResourceInfoFactory::emptyResource(),
            (new DigitalOceanDetector($this->vfs->url()))->getResource()
        );
        /** @psalm-suppress PossiblyFalseArgument */
        $this->assertStringContainsString(
            'Failed to detect DigitalOcean resource attributes',
            stream_get_contents($this->errorLog)
        );
    }

    public function test_not_digitalocean_environment()
    {
        /** mock sysfs with AWS SMBIOS pointers */
        $vfs = vfsStream::setup('/', structure: ['sys' => ['devices' => ['virtual' => ['dmi' => ['id' => [
            'sys_vendor' => 'AWS',
        ]]]]]]);

        $this->assertEquals(
            ResourceInfoFactory::emptyResource(),
            (new DigitalOceanDetector($vfs->url()))->getResource()
        );
        /** @psalm-suppress PossiblyFalseArgument */
        $this->assertStringContainsString(
            'DigitalOcean resource detector enabled in non-DigitalOcean environment',
            stream_get_contents($this->errorLog)
        );
    }

    public function test_not_linux()
    {
        /** mock sysfs with no SMBIOS pointers */
        $vfs = vfsStream::setup('/');

        $this->assertEquals(
            ResourceInfoFactory::emptyResource(),
            (new DigitalOceanDetector($vfs->url()))->getResource()
        );
        /** @psalm-suppress PossiblyFalseArgument */
        $this->assertStringContainsString(
            'DigitalOcean resource detector enabled in non-DigitalOcean environment',
            stream_get_contents($this->errorLog)
        );
    }
}
