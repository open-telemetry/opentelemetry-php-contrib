<?php

declare(strict_types=1);

namespace OpenTelemetry\Aws\Eks;

class DataProvider
{
    private const DEFAULT_CGROUP_PATH = '/proc/self/cgroup';
    private const K8S_TOKEN_PATH = '/var/run/secrets/kubernetes.io/serviceaccount/token';
    private const K8S_CERT_PATH = '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt';

    private string $cGroupPath;
    private string $k8sTokenPath;
    private string $k8sCertPath;

    public function __construct(?string $cGroupPath = null, ?string $k8sTokenPath = null, ?string $k8sCertPath = null)
    {
        $this->cGroupPath = $cGroupPath ?? self::DEFAULT_CGROUP_PATH;
        $this->k8sTokenPath = $k8sTokenPath ?? self::K8S_TOKEN_PATH;
        $this->k8sCertPath = $k8sCertPath ?? self::K8S_CERT_PATH;
    }

    public function getK8sHeader(): ?string
    {
        if (!file_exists($this->k8sTokenPath) || !is_readable($this->k8sTokenPath)) {
            //TODO: Add log 'Unable to load K8s client token'
            return null;
        }

        return 'Bearer' . (file_get_contents($this->k8sTokenPath) ?: '');
    }

    // Check if there exists a k8s certification file
    public function isK8s(): bool
    {
        return file_exists($this->k8sCertPath);
    }

    public function getCgroupData(): ?array
    {
        if (!file_exists($this->cGroupPath) || !is_readable($this->cGroupPath)) {
            return null;
        }

        return  file($this->cGroupPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: null;
    }
}
