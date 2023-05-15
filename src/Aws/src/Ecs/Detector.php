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

namespace OpenTelemetry\Aws\Ecs;

use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceDetectorInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SemConv\ResourceAttributes;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Throwable;

/**
 * The AwsEcsDetector can be used to detect if a process is running in AWS
 * ECS and return a {@link Resource} populated with data about the ECS
 * plugins of AWS ˜X-Ray. Returns an empty Resource if detection fails.
 */
class Detector implements ResourceDetectorInterface
{
    use LogsMessagesTrait;

    private const ECS_METADATA_KEY_V4 = 'ECS_CONTAINER_METADATA_URI_V4';
    private const ECS_METADATA_KEY_V3 = 'ECS_CONTAINER_METADATA_URI';

    private const CONTAINER_ID_LENGTH = 64;

    private const CLOUD_PROVIDER = 'aws';
    private const CLOUD_PLATFORM = 'aws_ecs';

    private DataProvider $processData;
    private ClientInterface $client;
    private RequestFactoryInterface $requestFactory;

    public function __construct(
        DataProvider $processData,
        ClientInterface $client,
        RequestFactoryInterface $requestFactory
    ) {
        $this->processData = $processData;
        $this->client = $client;
        $this->requestFactory = $requestFactory;
    }

    /**
     * If not running on ECS, returns empty resource.
     *
     * If running on ECS with an ECS agent v1.3, returns a resource with the following attributes set:
     * - cloud.provider => aws
     * - cloud.platform => aws_ecs
     * - container.name => <hostname>, which is usually the container name in the ECS task definition
     * - container.id => <cgroup_id>
     *
     * If running on ECS with an ECS agent v1.4, the returned resource has additionally the following
     * attributes as specified in https://opentelemetry.io/docs/reference/specification/resource/semantic_conventions/cloud_provider/aws/ecs/:
     *
     * - aws.ecs.container.arn
     * - aws.ecs.cluster.arn
     * - aws.ecs.launchtype
     * - aws.ecs.task.arn
     * - aws.ecs.task.family
     * - aws.ecs.task.revision
     *
     * If running on ECS with an ECS agent v1.4 and the task definition is configured to report
     * logs in AWS CloudWatch, the returned resource has additionally the following attributes as specified
     * in https://opentelemetry.io/docs/reference/specification/resource/semantic_conventions/cloud_provider/aws/logs:
     *
     * - aws.log.group.names
     * - aws.log.group.arns
     * - aws.log.stream.names
     * - aws.log.stream.arns
     */
    public function getResource(): ResourceInfo
    {
        $metadataEndpointV4 = getenv(self::ECS_METADATA_KEY_V4);

        if (!$metadataEndpointV4 && !getenv(self::ECS_METADATA_KEY_V3)) {
            return ResourceInfoFactory::emptyResource();
        }

        $basicEcsResource = ResourceInfo::create(Attributes::create([
            ResourceAttributes::CLOUD_PROVIDER => self::CLOUD_PROVIDER,
            ResourceAttributes::CLOUD_PLATFORM => self::CLOUD_PLATFORM,
        ]));

        $metadataV4Resource = $this->getMetadataEndpointV4Resource();

        $hostNameAndContainerIdResource = ResourceInfo::create(Attributes::create([
            ResourceAttributes::CONTAINER_NAME => $this->processData->getHostname(),
            ResourceAttributes::CONTAINER_ID => $this->getContainerId(),
        ]));

        return $basicEcsResource
            ->merge($hostNameAndContainerIdResource)
            ->merge($metadataV4Resource);
    }

    /**
     * Returns the docker ID of the container found
     * in its CGroup file.
     */
    private function getContainerId(): ?string
    {
        try {
            $cgroupData = $this->processData->getCgroupData();

            if (!$cgroupData) {
                return null;
            }

            foreach ($cgroupData as $str) {
                if (strlen($str) > self::CONTAINER_ID_LENGTH) {
                    return substr($str, strlen($str) - self::CONTAINER_ID_LENGTH);
                }
            }
        } catch (Throwable $e) {
            self::logDebug('Failed to read container ID', ['exception' => $e]);
        }

        return null;
    }

    private function getMetadataEndpointV4Resource(): ResourceInfo
    {
        $metadataEndpointV4 = getenv(self::ECS_METADATA_KEY_V4);
        if (!$metadataEndpointV4) {
            return ResourceInfoFactory::emptyResource();
        }

        $containerRequest = $this->requestFactory
            ->createRequest('GET', $metadataEndpointV4);
        $containerResponse = $this->client->sendRequest($containerRequest);
        if ($containerResponse->getStatusCode() > 299) {
            self::logError(sprintf('Cannot retrieve container metadata from %s endpoint', $metadataEndpointV4), [
                'status_code' => $containerResponse->getStatusCode(),
                'response_body' => $containerResponse->getBody()->getContents(),
            ]);

            return ResourceInfoFactory::emptyResource();
        }

        $taskRequest = $this->requestFactory
            ->createRequest('GET', $metadataEndpointV4 . '/task');
        $taskResponse = $this->client->sendRequest($taskRequest);
        if ($taskResponse->getStatusCode() > 299) {
            self::logError(sprintf('Cannot retrieve task metadata from %s endpoint', $metadataEndpointV4 . '/task'), [
                'status_code' => $taskResponse->getStatusCode(),
                'response_body' => $taskResponse->getBody()->getContents(),
            ]);

            return ResourceInfoFactory::emptyResource();
        }

        $containerMetadata = json_decode($containerResponse->getBody()->getContents(), true);
        $taskMetadata = json_decode($taskResponse->getBody()->getContents(), true);
        
        $launchType = isset($taskMetadata['LaunchType']) ? strtolower($taskMetadata['LaunchType']) : null;
        $taskFamily = isset($taskMetadata['Family']) ? $taskMetadata['Family'] : null;
        $taskRevision = isset($taskMetadata['Revision']) ? $taskMetadata['Revision'] : null;

        $clusterArn = null;
        $taskArn = null;
        if (isset($taskMetadata['Cluster']) && isset($taskMetadata['TaskARN'])) {
            $taskArn = $taskMetadata['TaskARN'];
            $lastIndexOfColon = strrpos($taskArn, ':');
            if ($lastIndexOfColon) {
                $baseArn = substr($taskArn, 0, $lastIndexOfColon);
                $cluster = $taskMetadata['Cluster'];
                $clusterArn = strpos($cluster, 'arn:') === 0 ? $cluster : $baseArn . ':cluster/' . $cluster;
            }
        }

        $containerArn = isset($containerMetadata['ContainerARN']) ? $containerMetadata['ContainerARN'] : null;
    
        $logResource = ResourceInfoFactory::emptyResource();
        if (isset($containerMetadata['LogOptions']) && isset($containerMetadata['LogDriver']) && $containerMetadata['LogDriver'] === 'awslogs') {
            $logOptions = $containerMetadata['LogOptions'];
            $logsGroupName = $logOptions['awslogs-group'];
            $logsStreamName = $logOptions['awslogs-stream'];

            $logsGroupArns = [];
            $logsStreamArns = [];
            if (isset($containerMetadata['ContainerARN']) && preg_match('/arn:aws:ecs:([^:]+):([^:]+):.*/', $containerMetadata['ContainerARN'], $matches)) {
                [$arn, $awsRegion, $awsAccount] = $matches;

                $logsGroupArns = ['arn:aws:logs:' . $awsRegion . ':' . $awsAccount . ':log-group:' . $logsGroupName];
                $logsStreamArns = ['arn:aws:logs:' . $awsRegion . ':' . $awsAccount . ':log-group:' . $logsGroupName . ':log-stream:' . $logsStreamName];
            }

            $logResource = ResourceInfo::create(Attributes::create([
                ResourceAttributes::AWS_LOG_GROUP_NAMES => [$logsGroupName],
                ResourceAttributes::AWS_LOG_GROUP_ARNS => $logsGroupArns,
                ResourceAttributes::AWS_LOG_STREAM_NAMES => [$logsStreamName],
                ResourceAttributes::AWS_LOG_STREAM_ARNS => $logsStreamArns,
            ]));
        }

        $ecsResource = ResourceInfo::create(Attributes::create([
            ResourceAttributes::AWS_ECS_CONTAINER_ARN => $containerArn,
            ResourceAttributes::AWS_ECS_CLUSTER_ARN => $clusterArn,
            ResourceAttributes::AWS_ECS_LAUNCHTYPE => $launchType,
            ResourceAttributes::AWS_ECS_TASK_ARN => $taskArn,
            ResourceAttributes::AWS_ECS_TASK_FAMILY => $taskFamily,
            ResourceAttributes::AWS_ECS_TASK_REVISION => $taskRevision,
        ]));

        return $ecsResource->merge($logResource);
    }
}
