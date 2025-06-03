<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Resource\Detector\DigitalOcean;

use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\SDK\Common\Adapter\HttpDiscovery\MessageFactoryResolver;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Http\Psr\Client\Discovery;
use OpenTelemetry\SDK\Resource\ResourceDetectorInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SemConv\ResourceAttributes;
use Throwable;

/**
 * @see https://github.com/open-telemetry/semantic-conventions/blob/main/docs/resource/cloud.md
 * @psalm-suppress UnusedClass
 */
final class DigitalOceanDetector implements ResourceDetectorInterface
{
    use LogsMessagesTrait;

    /** @see https://docs.digitalocean.com/products/droplets/how-to/access-metadata/ */
    private const DO_METADATA_ENDPOINT_URL = 'http://169.254.169.254/metadata/v1.json';
    private const DO_ACCOUNT_ENDPOINT_URL = 'https://api.digitalocean.com/v2/account';
    public const CLOUD_PROVIDER = 'digitalocean';
    public const CLOUD_PLATFORM = 'digitalocean_droplet';

    public function getResource(): ResourceInfo
    {
        try {
            $requestFactory = MessageFactoryResolver::create()->resolveRequestFactory();
            $client = Discovery::find();

            $metadataRequest = $requestFactory->createRequest('GET', self::DO_METADATA_ENDPOINT_URL);
            $metadataResponse = $client->sendRequest($metadataRequest);
            $metadata = json_decode($metadataResponse->getBody()->getContents(), flags: JSON_THROW_ON_ERROR);

            $attributes = [
                //ResourceAttributes::CLOUD_AVAILABILITY_ZONE DigitalOcean does not distinguish between this and region
                ResourceAttributes::CLOUD_PLATFORM    => self::CLOUD_PLATFORM,
                ResourceAttributes::CLOUD_PROVIDER    => self::CLOUD_PROVIDER,
                ResourceAttributes::CLOUD_REGION      => $metadata->region,
                ResourceAttributes::CLOUD_RESOURCE_ID => (string) $metadata->droplet_id,
            ];

            /**
             * The DigitalOcean Account ID is not available from the metadata API, however if PHP has access to the
             * `DIGITALOCEAN_ACCESS_TOKEN` environment variable, and the token is scoped to allow the reading of account
             * details, we can try looking it up that way. (This will be rare.)
             */
            if (key_exists('DIGITALOCEAN_ACCESS_TOKEN', $_ENV)) {
                try {
                    $accountRequest = $requestFactory
                        ->createRequest('GET', self::DO_ACCOUNT_ENDPOINT_URL)
                        ->withHeader('Authorization', sprintf('Bearer %s', $_ENV['DIGITALOCEAN_ACCESS_TOKEN']));
                    $accountResponse = $client->sendRequest($accountRequest);
                    $account = json_decode($accountResponse->getBody()->getContents(), flags: JSON_THROW_ON_ERROR);

                    $attributes[ResourceAttributes::CLOUD_ACCOUNT_ID] = $account->team->uuid;
                } catch (Throwable $t) {
                    self::logNotice('DigitalOcean Access Token found, but unable to get account ID', ['exception' => $t]);
                    /** We tried, but we still want the detector to return the other attributes. */
                }
            }

            return ResourceInfo::create(Attributes::create($attributes), ResourceAttributes::SCHEMA_URL);
        } catch (Throwable $t) {
            self::logWarning('Failed to detect DigitalOcean Droplet metadata', ['exception' => $t]);

            return ResourceInfo::emptyResource();
        }
    }
}
