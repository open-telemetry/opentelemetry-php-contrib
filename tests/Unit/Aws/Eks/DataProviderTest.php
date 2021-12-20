<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Aws\Eks;

use OpenTelemetry\Aws\Eks\DataProvider;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

class DataProviderTest extends TestCase
{
    private const ROOT_DIR = '/';
    private const CGROUP_PATH = '/cgroup';
    private const TOKEN_PATH = '/token';
    private const CERT_PATH = '/ca.crt';

    public function setUp(): void
    {
        vfsStream::setup(self::ROOT_DIR);
    }

    public function testGetK8sHeader()
    {
        $file = vfsStream::newFile(self::TOKEN_PATH);
        file_put_contents($file->path(), 'foo');

        $provider = new DataProvider(
            null,
            $file->path(),
            null
        );

        $this->assertIsString($provider->getK8sHeader());
    }

    public function testGetK8sHeaderNoTokenFile()
    {
        $provider = new DataProvider(
            null,
            vfsStream::url(self::TOKEN_PATH),
            null
        );

        $this->assertNull($provider->getK8sHeader());
    }

    public function testIsK8s()
    {
        $file = vfsStream::newFile(self::CERT_PATH);

        $provider = new DataProvider(
            null,
            null,
            $file->path()
        );

        $this->assertFalse($provider->isK8s());

        file_put_contents($file->path(), 'foo');

        $this->assertTrue($provider->isK8s());
    }

    public function testGetCgroupData()
    {
        $file = vfsStream::newFile(self::CGROUP_PATH);
        file_put_contents($file->path(), "foo\nbar");

        $provider = new DataProvider(
            $file->path(),
            null,
            null
        );

        $this->assertIsArray($provider->getCgroupData());
    }

    public function testGetCgroupDataNoCgroupFile()
    {
        $provider = new DataProvider(
            vfsStream::url(self::CGROUP_PATH),
            null,
            null
        );

        $this->assertNull($provider->getCgroupData());
    }
}
