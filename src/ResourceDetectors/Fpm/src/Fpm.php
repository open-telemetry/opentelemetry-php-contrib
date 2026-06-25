<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Resource\Detector\Fpm;

use function function_exists;
use function gethostname;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceDetectorInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SemConv\Incubating\Attributes\ServiceIncubatingAttributes;
use OpenTelemetry\SemConv\Version;
use function php_sapi_name;
use Ramsey\Uuid\Uuid;

/**
 * FPM resource detector that provides stable service instance IDs to avoid high cardinality issues.
 *
 * For FPM environments, generates a stable instance ID based on the pool name and hostname
 * rather than using random UUIDs which cause cardinality explosion in metrics.
 */
final class Fpm implements ResourceDetectorInterface
{
    public function getResource(): ResourceInfo
    {
        // Only activate for FPM SAPI
        if (php_sapi_name() !== 'fpm-fcgi') {
            return ResourceInfoFactory::emptyResource();
        }

        $attributes = [
            ServiceIncubatingAttributes::SERVICE_INSTANCE_ID => $this->getStableInstanceId(),
        ];

        // Add FPM-specific attributes
        if (function_exists('fastcgi_finish_request')) {
            $poolName = $this->getFpmPoolName();
            if ($poolName !== null) {
                $attributes['process.runtime.pool'] = $poolName;
            }
        }

        return ResourceInfo::create(Attributes::create($attributes), Version::VERSION_1_36_0->url());
    }

    /**
     * Generate a stable service instance ID for FPM processes.
     *
     * Uses pool name + hostname to create a deterministic UUID v5 that remains
     * consistent across FPM process restarts within the same pool.
     */
    private function getStableInstanceId(): string
    {
        $components = [
            'fpm',
            $this->getFpmPoolName() ?? 'default',
            gethostname() ?: 'localhost',
        ];

        // Create a stable UUID v5 using a namespace UUID and deterministic name
        $namespace = Uuid::fromString('4d63009a-8d0f-11ee-aad7-4c796ed8e320'); // DNS namespace UUID
        $name = implode('-', $components);

        return Uuid::uuid5($namespace, $name)->toString();
    }

    /**
     * Attempt to determine the FPM pool name from environment or server variables.
     */
    private function getFpmPoolName(): ?string
    {
        // Try common FPM pool identification methods
        if (isset($_SERVER['FPM_POOL'])) {
            return $_SERVER['FPM_POOL'];
        }

        if (isset($_ENV['FPM_POOL'])) {
            return $_ENV['FPM_POOL'];
        }

        // Fallback: try to extract from process title if available
        if (function_exists('cli_get_process_title')) {
            $title = cli_get_process_title();
            if ($title && preg_match('/pool\s+(\w+)/', $title, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }
}
