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

namespace OpenTelemetry\Contrib\Aws\Ec2;

use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceDetectorInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SemConv\ResourceAttributes;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * The AwsEc2Detector can be used to detect if a process is running in AWS EC2
 * and return a Resource populated with metadata about the EC2
 * instance. Returns an empty Resource if detection fails.
 */
class Detector implements ResourceDetectorInterface
{
    private const SCHEME = 'http://';
    private const AWS_IDMS_ENDPOINT = '169.254.169.254';
    private const AWS_INSTANCE_TOKEN_DOCUMENT_PATH = '/latest/api/token';
    private const AWS_INSTANCE_IDENTITY_DOCUMENT_PATH = '/latest/dynamic/instance-identity/document';
    private const AWS_INSTANCE_HOST_DOCUMENT_PATH = '/latest/meta-data/hostname';
    private const AWS_METADATA_TTL_HEADER = 'X-aws-ec2-metadata-token-ttl-seconds';
    private const AWS_METADATA_TOKEN_HEADER = 'X-aws-ec2-metadata-token';
    private const CLOUD_PROVIDER = 'aws';
    private const CLOUD_PLATFORM = 'aws_ec2';

    private ClientInterface $client;
    private RequestFactoryInterface $requestFactory;

    public function __construct(ClientInterface $client, RequestFactoryInterface $requestFactory)
    {
        $this->client = $client;
        $this->requestFactory = $requestFactory;
    }

    /**
     * Attempts to connect and obtain an AWS instance Identity document. If the
     * connection is successful it returns a Resource
     * populated with instance metadata. Returns an empty Resource
     * if the connection or parsing of the identity document fails.
     *
     */
    public function getResource(): ResourceInfo
    {
        try {
            $token = $this->fetchToken();

            if ($token === null) {
                return ResourceInfoFactory::emptyResource();
            }

            $hostName = $this->fetchHostname($token);

            $identitiesJson = $this->fetchIdentity($token);

            if (!$token || !$identitiesJson) {
                return ResourceInfoFactory::emptyResource();
            }

            $attributes = [];

            foreach ($identitiesJson as $key => $value) {
                switch ($key) {
                    case 'instanceId':
                        $attributes[ResourceAttributes::HOST_ID] = $value;

                        break;
                    case 'availabilityZone':
                        $attributes[ResourceAttributes::CLOUD_AVAILABILITY_ZONE] = $value;

                        break;
                    case 'instanceType':
                        $attributes[ResourceAttributes::HOST_TYPE] = $value;

                        break;
                    case 'imageId':
                        $attributes[ResourceAttributes::HOST_IMAGE_ID] = $value;

                        break;
                    case 'accountId':
                        $attributes[ResourceAttributes::CLOUD_ACCOUNT_ID] = $value;

                        break;
                    case 'region':
                        $attributes[ResourceAttributes::CLOUD_REGION] = $value;

                        break;
                }
            }

            $attributes[ResourceAttributes::HOST_NAME] = $hostName;
            $attributes[ResourceAttributes::CLOUD_PROVIDER] = self::CLOUD_PROVIDER;
            $attributes[ResourceAttributes::CLOUD_PLATFORM] = self::CLOUD_PLATFORM;

            return ResourceInfo::create(Attributes::create($attributes), ResourceAttributes::SCHEMA_URL);
        } catch (\Throwable $e) {
            //TODO: add 'Process is not running on K8S when logging is added
            return ResourceInfoFactory::emptyResource();
        }
    }

    private function fetchToken(): ?string
    {
        $request = $this->createRequest('PUT', self::AWS_INSTANCE_TOKEN_DOCUMENT_PATH)
            ->withHeader(self::AWS_METADATA_TTL_HEADER, '60');

        return $this->request($request);
    }

    private function fetchIdentity(String $token): ?array
    {
        $request = $this->createRequest('GET', self::AWS_INSTANCE_IDENTITY_DOCUMENT_PATH)
            ->withHeader(self::AWS_METADATA_TOKEN_HEADER, $token);
        $body = $this->request($request);

        if (empty($body)) {
            return null;
        }

        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $t) {
            return null;
        }
    }

    private function fetchHostname(String $token): ?string
    {
        $request = $this->createRequest('GET', self::AWS_INSTANCE_HOST_DOCUMENT_PATH)
            ->withHeader(self::AWS_METADATA_TOKEN_HEADER, $token);

        return $this->request($request);
    }

    private function createRequest(string $method, string $path): RequestInterface
    {
        return $this->requestFactory->createRequest($method, self::SCHEME . self::AWS_IDMS_ENDPOINT . $path);
    }

    /**
     * Function to create a request for any of the given
     * fetch functions.
     */
    private function request(RequestInterface $request): ?string
    {
        $client = $this->client;

        try {
            $response = $client->sendRequest($request);

            $body = $response->getBody()->getContents();
            $responseCode = $response->getStatusCode();

            if (!empty($body) && $responseCode < 300 && $responseCode >= 200) {
                return $body;
            }

            return null;
        } catch (Throwable $e) {
            // TODO: add log for exception. The code below
            // provides the exception thrown:
            // echo Psr7\Message::toString($e->getRequest());
            // if ($e->hasResponse()) {
            //     echo Psr7\Message::toString($e->getResponse());
            // }
            return null;
        }
    }
}
