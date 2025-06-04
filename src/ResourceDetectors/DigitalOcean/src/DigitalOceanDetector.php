<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Resource\Detector\DigitalOcean;

use Fig\Http\Message\RequestMethodInterface as HTTP;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\SDK\Common\Adapter\HttpDiscovery\MessageFactoryResolver;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Http\Psr\Client\Discovery;
use OpenTelemetry\SDK\Resource\ResourceDetectorInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SemConv\ResourceAttributes;
use OpenTelemetry\SemConv\ResourceAttributeValues;
use Throwable;
use UnexpectedValueException;

/**
 * @see https://github.com/open-telemetry/semantic-conventions/blob/main/docs/resource/cloud.md
 * @psalm-suppress UnusedClass
 */
final class DigitalOceanDetector implements ResourceDetectorInterface
{
    use LogsMessagesTrait;

    /** @see https://docs.digitalocean.com/products/droplets/how-to/access-metadata/ */
    private const DO_METADATA_ENDPOINT_URL = 'http://169.254.169.254/metadata/v1.json';
    private const DO_PUBLIC_ENDPOINT_URL = 'https://api.digitalocean.com/v2/';
    private const ENV_DO_API_TOKEN = 'DIGITALOCEAN_ACCESS_TOKEN';

    public function getResource(): ResourceInfo
    {
        $client = Discovery::find();
        $requestFactory = MessageFactoryResolver::create()->resolveRequestFactory();
        $token = $_ENV[self::ENV_DO_API_TOKEN] ?? '';

        /** Bail early if wrong environment */
        if (!self::isDigitalOcean()) {
            self::logNotice('DigitalOcean resource detector enabled in non-DigitalOcean environment');

            return ResourceInfoFactory::emptyResource();
        }

        try {
            /** Attributes available locally - all non-privileged lookups */
            $attributes = [
                ResourceAttributes::CLOUD_PLATFORM => self::readSMBIOS('product_family'),
                ResourceAttributes::CLOUD_PROVIDER => self::readSMBIOS('sys_vendor'),
                ResourceAttributes::HOST_ARCH => ResourceAttributeValues::HOST_ARCH_AMD64,
                ResourceAttributes::HOST_ID => self::readSMBIOS('board_asset_tag'),
                ResourceAttributes::HOST_NAME => gethostname(),
                ResourceAttributes::OS_TYPE => ResourceAttributeValues::OS_TYPE_LINUX,
            ];

            /** Attributes available without authentication via the link-local IP API */
            $metadataRequest = $requestFactory->createRequest('GET', self::DO_METADATA_ENDPOINT_URL);
            $metadataResponse = $client->sendRequest($metadataRequest);
            $metadata = json_decode($metadataResponse->getBody()->getContents(), flags: JSON_THROW_ON_ERROR);

            $attributes[ResourceAttributes::CLOUD_REGION] = $metadata->region;
            $attributes[ResourceAttributes::CLOUD_RESOURCE_ID] = (string) $metadata->droplet_id;
        } catch (Throwable $t) {
            self::logWarning('Failed to detect DigitalOcean resource attributes', ['exception' => $t]);

            return ResourceInfoFactory::emptyResource();
        }

        /** Attributes available by authenticating to the public V2 API (This will be rare.) */
        if ($token !== '') {
            // Two different API scopes; a token may have access to neither, one, or both
            try {
                $accountRequest = $requestFactory
                    ->createRequest(
                        HTTP::METHOD_GET,
                        sprintf('%saccount', self::DO_PUBLIC_ENDPOINT_URL)
                    )
                    ->withHeader('Authorization', sprintf('Bearer %s', $token));
                $accountResponse = $client->sendRequest($accountRequest);
                $account = json_decode($accountResponse->getBody()->getContents(), flags: JSON_THROW_ON_ERROR)->account;

                $attributes[ResourceAttributes::CLOUD_ACCOUNT_ID] = $account->team->uuid;
            } catch (Throwable $t) {
                self::logNotice(
                    'DigitalOcean Access Token found, but unable to get account info',
                    ['exception' => $t]
                );
            }

            try {
                $dropletRequest = $requestFactory
                    ->createRequest(
                        HTTP::METHOD_GET,
                        sprintf('%sdroplets/%s', self::DO_PUBLIC_ENDPOINT_URL, (string) $metadata->droplet_id)
                    )
                    ->withHeader('Authorization', sprintf('Bearer %s', $token));
                $dropletResponse = $client->sendRequest($dropletRequest);
                $droplet = json_decode($dropletResponse->getBody()->getContents(), flags: JSON_THROW_ON_ERROR)->droplet;

                $attributes[ResourceAttributes::HOST_IMAGE_ID] = (string) $droplet->image->id;
                $attributes[ResourceAttributes::HOST_IMAGE_NAME] = $droplet->image->name;
                $attributes[ResourceAttributes::HOST_TYPE] = $droplet->size_slug;
                $attributes[ResourceAttributes::OS_NAME] = $droplet->image->distribution;
            } catch (Throwable $t) {
                self::logNotice(
                    'DigitalOcean Access Token found, but unable to get Droplet info',
                    ['exception' => $t]
                );
            }
        }

        return ResourceInfo::create(Attributes::create($attributes), ResourceAttributes::SCHEMA_URL);
    }

    private static function isDigitalOcean(): bool
    {
        try {
            $sysVendor = self::readSMBIOS('sys_vendor');
        } catch (UnexpectedValueException) {
            return false;
        }
        if (PHP_OS_FAMILY !== 'Linux' || $sysVendor !== 'digitalocean') {
            return false;
        }

        return true;
    }

    private static function readSMBIOS(string $dmiKeyword): string
    {
        $dmiValue = file_get_contents(sprintf('/sys/devices/virtual/dmi/id/%s', $dmiKeyword));
        if ($dmiValue === false) {
            throw new UnexpectedValueException('Failed to read SMBIOS value from sysfs.');
        }

        return strtolower($dmiValue);
    }
}
