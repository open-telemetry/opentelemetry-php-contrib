<?php

declare(strict_types=1);

namespace OpenTelemetry\Aws\Eks;

class DataProvider
{
    private const DEFAULT_CGROUP_PATH = '/proc/self/cgroup';
    private const K8S_TOKEN_PATH = '/var/run/secrets/kubernetes.io/serviceaccount/token';
    private const K8S_CERT_PATH = '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt';

    public function getK8sHeader()
    {
        $credHeader = file_get_contents(self::K8S_TOKEN_PATH);

        if ($credHeader) {
            return 'Bearer' . $credHeader;
        }

        //TODO: Add log 'Unable to load K8s client token'
        return null;
    }

    // Check if there exists a k8s certification file
    public function isK8s()
    {
        return file_get_contents(self::K8S_CERT_PATH);
    }

    public function getCgroupData()
    {
        return file(self::DEFAULT_CGROUP_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    }
}
