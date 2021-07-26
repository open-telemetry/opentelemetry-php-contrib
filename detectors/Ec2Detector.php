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

namespace Detectors\Aws;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use OpenTelemetry\Sdk\Resource\ResourceConstants;
use OpenTelemetry\Sdk\Resource\ResourceInfo;
use OpenTelemetry\Sdk\Trace\Attributes;

/**
 * The AwsEc2Detector can be used to detect if a process is running in AWS EC2
 * and return a {@link Resource} populated with metadata about the EC2
 * instance. Returns an empty Resource if detection fails.
 */
class Ec2Detector
{
    private const SCHEME = 'http://';
    private const AWS_IDMS_ENDPOINT = '169.254.169.254';
    private const AWS_INSTANCE_TOKEN_DOCUMENT_PATH = '/latest/api/token';
    private const AWS_INSTANCE_IDENTITY_DOCUMENT_PATH = '/latest/dynamic/instance-identity/document';
    private const AWS_INSTANCE_HOST_DOCUMENT_PATH = '/latest/meta-data/hostname';
    private const AWS_METADATA_TTL_HEADER = 'X-aws-ec2-metadata-token-ttl-seconds';
    private const AWS_METADATA_TOKEN_HEADER = 'X-aws-ec2-metadata-token';
    private const MILLISECOND_TIME_OUT = 1000;
    private const CLOUD_PROVIDER = 'aws';

    private $guzzle;

    public function __construct(Client $guzzle)
    {
        $this->guzzle = $guzzle;
    }

    /**
     * Attempts to connect and obtain an AWS instance Identity document. If the
     * connection is succesful it returns a {@link Resource}
     * populated with instance metadata. Returns an empty {@link Resource} 
     * if the connection or parsing of the identity document fails.
     *
     * @param config (unused) The resource detection config
     */
    public function detect()
    {
        try {
            $token = $this->fetchToken();
            
            $hostName = $this->fetchHostname($token);

            $identitiesJson = $this->fetchIdentity($token);

            if (!$token || !$identitiesJson) return ResourceInfo::emptyResource();
            
            $attributes = new Attributes();

            foreach ($identitiesJson as $key => $value) {
                switch($key) {
                    case 'instanceId': 
                        $attributes->setAttribute(ResourceConstants::HOST_ID, $value);
                        break;
                    case 'availabilityZone':
                        $attributes->setAttribute(ResourceConstants::CLOUD_ZONE, $value);
                        break;
                    case 'instanceType':
                        $attributes->setAttribute(ResourceConstants::HOST_TYPE, $value);
                        break;
                    case 'imageId':
                        $attributes->setAttribute(ResourceConstants::HOST_IMAGE_ID, $value);
                        break;
                    case 'accountId':
                        $attributes->setAttribute(ResourceConstants::CLOUD_ACCOUNT_ID, $value);
                        break;
                    case 'region':
                        $attributes->setAttribute(ResourceConstants::CLOUD_REGION, $value);
                        break;
                }
            }

            $attributes->setAttribute(ResourceConstants::HOST_HOSTNAME, $hostName);
            $attributes->setAttribute(ResourceConstants::CLOUD_PROVIDER, self::CLOUD_PROVIDER);

            return ResourceInfo::create($attributes);
        } catch (\Throwable $e) {
            //TODO: add 'Process is not running on K8S when logging is added
            return ResourceInfo::emptyResource();
        }
    }

    private function fetchToken()
    {
        return $this->request(
                    'PUT', 
                    self::AWS_INSTANCE_TOKEN_DOCUMENT_PATH, 
                    [self::AWS_METADATA_TTL_HEADER => '60']
                );
    }

    private function fetchIdentity(String $token)
    {
        $body = $this->request(
                    'GET',
                    self::AWS_INSTANCE_IDENTITY_DOCUMENT_PATH,
                    [self::AWS_METADATA_TOKEN_HEADER => $token]
                );

        $json = json_decode($body, true);

        if (isset($json)) return $json;

        return null;
    }

    private function fetchHostname(String $token)
    {
        return $this->request(
                    'GET',
                    self::AWS_INSTANCE_HOST_DOCUMENT_PATH,
                    [self::AWS_METADATA_TOKEN_HEADER => $token]
                );
    }

    /**
     * Function to create a request for any of the given 
     * fetch functions.
     */
    private function request($method, $path, $header)
    {
        $client = $this->guzzle;

        try {
            $response = $client->request(
                $method,
                self::SCHEME . self::AWS_IDMS_ENDPOINT . $path,
                [
                    'headers' => $header,
                    'timeout' => self::MILLISECOND_TIME_OUT,
                ]
            );

            $body = $response->getBody()->getContents();
            $responseCode = $response->getStatusCode();

            if (!empty($body) && $responseCode < 300 && $responseCode >= 200) return $body;
            
            return null;
        } catch (RequestException $e) {
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