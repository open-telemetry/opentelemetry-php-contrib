<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Resource\Detector\Container;

use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceDetectorInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SemConv\ResourceAttributes;

/**
 * @see https://github.com/open-telemetry/opentelemetry-specification/blob/v1.18.0/specification/resource/semantic_conventions/container.md
 * @see https://github.com/open-telemetry/opentelemetry-java-instrumentation/blob/v1.29.0/instrumentation/resources/library/src/main/java/io/opentelemetry/instrumentation/resources/ContainerResource.java
 */
final class Container implements ResourceDetectorInterface
{
    private string $dir;
    private const CONTAINER_ID_REGEX = '/^[0-9a-f]{64}$/';
    private const V1_CONTAINER_ID_REGEX = '/\.?[0-9a-f]{64}\-?/';
    private const CGROUP_V1 = 'cgroup';
    private const CGROUP_V2 = 'mountinfo';
    private const HOSTNAME = 'hostname';

    public function __construct(string $dir = '/proc/self')
    {
        $this->dir = $dir;
    }

    public function getResource(): ResourceInfo
    {
        $attributes = [];
        $id = $this->getContainerId();
        if ($id) {
            $attributes[ResourceAttributes::CONTAINER_ID] = $id;
        }

        return ResourceInfo::create(Attributes::create($attributes), ResourceAttributes::SCHEMA_URL);
    }

    private function getContainerId(): ?string
    {
        return $this->getContainerIdV1() ?? $this->getContainerIdV2();
    }

    /**
     * Each line of cgroup file looks like "14:name=systemd:/docker/.../... A hex string is expected
     * inside the last section separated by '/' Each segment of the '/' can contain metadata separated
     * by either '.' (at beginning) or '-' (at end)
     */
    private function getContainerIdV1(): ?string
    {
        if (!file_exists(sprintf('%s/%s', $this->dir, self::CGROUP_V1))) {
            return null;
        }
        $data = file_get_contents(sprintf('%s/%s', $this->dir, self::CGROUP_V1));
        if (!$data) {
            return null;
        }
        $lines = explode(PHP_EOL, $data);
        foreach ($lines as $line) {
            if (strpos($line, '/') === false) {
                continue;
            }
            $parts = explode('/', $line);
            $section = end($parts);
            $colon = strrpos($section, ':');
            if ($colon !== false) {
                return substr($section, $colon);
            }
            $matches = [];
            if (preg_match(self::V1_CONTAINER_ID_REGEX, $section, $matches) === 1) {
                return $matches[0];
            }
        }

        return null;
    }

    private function getContainerIdV2(): ?string
    {
        if (!file_exists(sprintf('%s/%s', $this->dir, self::CGROUP_V2))) {
            return null;
        }
        $data = file_get_contents(sprintf('%s/%s', $this->dir, self::CGROUP_V2));
        if (!$data) {
            return null;
        }
        $lines = explode(PHP_EOL, $data);
        foreach ($lines as $line) {
            if (strpos($line, self::HOSTNAME) !== false) {
                $parts = explode('/', $line);
                foreach ($parts as $part) {
                    if (preg_match(self::CONTAINER_ID_REGEX, $part) === 1) {
                        return $part;
                    }
                }
            }
        }

        return null;
    }
}
