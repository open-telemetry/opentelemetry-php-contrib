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

namespace OpenTelemetry\Aws\Eks;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use OpenTelemetry\SDK\Attributes;
use OpenTelemetry\SDK\Resource\ResourceDetectorInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SemConv\ResourceAttributes;

/**
 * The AwsEksDetector can be used to detect if a process is running in AWS
 * Elastic Kubernetes and return a {@link Resource} populated with data about the Kubernetes
 * plugins of AWS X-Ray. Returns an empty Resource if detection fails.
 */
class Detector implements ResourceDetectorInterface
{
    // Credentials and path for locating API
    private const K8S_SVC_URL = 'kubernetes.default.svc';
    private const K8S_CERT_PATH = '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt';

    // Path for Amazon CloudWatch Logs
    private const AUTH_CONFIGMAP_PATH = '/api/v1/namespaces/kube-system/configmaps/aws-auth';
    private const CW_CONFIGMAP_PATH = '/api/v1/namespaces/amazon-cloudwatch/configmaps/cluster-info';

    private const CONTAINER_ID_LENGTH = 64;

    private DataProvider $dataProvider;
    private Client $client;
    
    public function __construct(DataProvider $dataProvider, Client $client)
    {
        $this->dataProvider = $dataProvider;
        $this->client = $client;
    }
    
    public function getResource(): ResourceInfo
    {
        try {
            if (!$this->dataProvider->isK8s() || !$this->isEks()) {
                return ResourceInfo::emptyResource();
            }

            $clusterName = $this->getClusterName();
            $containerId = $this->getContainerId();
    
            return !$clusterName && !$containerId
                ? ResourceInfo::emptyResource()
                : ResourceInfo::create(new Attributes([
                    ResourceAttributes::CONTAINER_ID => $containerId,
                    ResourceAttributes::K8S_CLUSTER_NAME => $clusterName,
                ]));
        } catch (\Throwable $e) {
            //TODO: add 'Process is not running on K8S when logging is added
            return ResourceInfo::emptyResource();
        }
    }

    private function getContainerId(): ?string
    {
        try {
            $cgroupData = $this->dataProvider->getCgroupData();

            if (!$cgroupData) {
                return null;
            }

            foreach ($cgroupData as $str) {
                if (strlen($str) > self::CONTAINER_ID_LENGTH) {
                    return substr($str, strlen($str) - self::CONTAINER_ID_LENGTH);
                }
            }

            return null;
        } catch (\Throwable $e) {
            //TODO: add 'Failed to read container ID' when logging is added
            return null;
        }
    }

    public function getClusterName(): ?string
    {
        // Create a request to AWS Config map which determines
        // whether the process is running on an EKS
        $client = $this->client;

        try {
            $response = $client->request(
                'GET',
                'https://' . self::K8S_SVC_URL . self::CW_CONFIGMAP_PATH,
                [
                    'headers' => [
                        'Authorization' => $this->dataProvider->getK8sHeader() . ', Bearer ' . self::K8S_CERT_PATH,
                    ],
                    'timeout' => 2000,
                ]
            );

            $json = json_decode($response->getBody()->getContents(), true);

            if (isset($json['data']['cluster.name'])) {
                return $json['data']['cluster.name'];
            }

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

    // Create a request to AWS Config map which determines
    // whether the process is running on an EKS
    public function isEks(): bool
    {
        $client = $this->client;

        try {
            $response = $client->request(
                'GET',
                'https://' . self::K8S_SVC_URL . self::AUTH_CONFIGMAP_PATH,
                [
                    'headers' => [
                        'Authorization' => $this->dataProvider->getK8sHeader() . ', Bearer ' . self::K8S_CERT_PATH,
                    ],
                    'timeout' => 2000,
                ]
            );

            $body = $response->getBody()->getContents();
            $responseCode = $response->getStatusCode();

            return !empty($body) && $responseCode < 300 && $responseCode >= 200;
        } catch (RequestException $e) {
            // TODO: add log for exception. The code below
            // provides the exception thrown:
            // echo Psr7\Message::toString($e->getRequest());
            // if ($e->hasResponse()) {
            //     echo Psr7\Message::toString($e->getResponse());
            // }

            return false;
        }
    }
}
