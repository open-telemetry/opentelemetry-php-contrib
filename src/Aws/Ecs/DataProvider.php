<?php

declare(strict_types=1);

namespace OpenTelemetry\Aws\Ecs;

class DataProvider
{
    private const DEFAULT_CGROUP_PATH = '/proc/self/cgroup';

    /**
     * Returns the host name of the container the process is in.
     * This would be the os the container is running on,
     * i.e. the platform on which it is deployed
     */
    public function getHostName()
    {
        return php_uname('n');
    }

    /**
     * Get data from the Cgroup file
     */
    public function getCgroupData()
    {
        return file(self::DEFAULT_CGROUP_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    }
}
