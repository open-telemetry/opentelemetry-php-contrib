<?php

declare(strict_types=1);

/*
 * Copyright The OpenTelemetry Authors
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      https://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace OpenTelemetry\Azure\Vm;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceDetectorInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SemConv\ResourceAttributes;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Throwable;

/**
 * AzureVmDetector
 */
class Detector implements ResourceDetectorInterface
{
    use LogsMessagesTrait;

    /** @see https://learn.microsoft.com/en-us/azure/virtual-machines/instance-metadata-service?tabs=windows */
    private const AZURE_METADATA_ENDPOINT_URL = 'http://169.254.169.254/metadata/instance/compute?api-version=2021-12-13&format=json';
    public const CLOUD_PROVIDER = 'azure';
    public const CLOUD_PLATFORM = 'azure_vm';
    private ClientInterface $client;
    private RequestFactoryInterface $requestFactory;

    public function __construct(
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null
    ) {
        $this->client = $client ?: Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?: Psr17FactoryDiscovery::findRequestFactory();
    }

    public function getResource(): ResourceInfo
    {
        try {
            $metadata = $this->getAzureMetadata();
            $attributes = [
                'azure.vm.scaleset.name' => $metadata['compute']['vmScaleSetName'],
                'azure.vm.sku' => $metadata['compute']['sku'],
                ResourceAttributes::CLOUD_PLATFORM => self::CLOUD_PLATFORM,
                ResourceAttributes::CLOUD_PROVIDER => self::CLOUD_PROVIDER,
                ResourceAttributes::CLOUD_REGION => $metadata['compute']['location'],
                ResourceAttributes::CLOUD_RESOURCE_ID => $metadata['compute']['resourceId'],
                ResourceAttributes::HOST_ID => $metadata['compute']['vmId'],
                ResourceAttributes::HOST_NAME => $metadata['compute']['name'],
                ResourceAttributes::HOST_TYPE => $metadata['compute']['vmSize'],
                ResourceAttributes::OS_TYPE => $metadata['compute']['osType'],
                ResourceAttributes::OS_VERSION => $metadata['compute']['version'],
                ResourceAttributes::SERVICE_INSTANCE_ID => $metadata['compute']['vmId'],
            ];

            return ResourceInfo::create(Attributes::create($attributes), ResourceAttributes::SCHEMA_URL);
        } catch (Throwable $e) {
            self::logWarning('Failed to detect Azure VM metadata', ['exception' => $e]);

            return ResourceInfo::emptyResource();
        }
    }

    private function getAzureMetadata()
    {
        $req = $this->requestFactory->createRequest('GET', self::AZURE_METADATA_ENDPOINT_URL);
        $req = $req->withHeader('Metadata', 'true');
        $res = $this->client->sendRequest($req);

        return json_decode($res->getBody()->getContents(), true);
    }
}
