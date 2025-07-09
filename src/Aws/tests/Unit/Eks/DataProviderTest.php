<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Aws\Unit\Eks;

use OpenTelemetry\Contrib\Aws\Eks\DataProvider;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase;

class DataProviderTest extends TestCase
{
    private const ROOT_DIR = 'root';
    private const CGROUP_PATH = self::ROOT_DIR . '/cgroup';
    private const TOKEN_PATH = self::ROOT_DIR . '/token';
    private const CERT_PATH = self::ROOT_DIR . '/ca.crt';

    private vfsStreamDirectory $root;

    public function setUp(): void
    {
        $this->root = vfsStream::setup(self::ROOT_DIR);
    }

    public function testGetK8sHeader(): void
    {
        $file = vfsStream::newFile(self::TOKEN_PATH)
            ->withContent('foo')
            ->at($this->root);

        $provider = new DataProvider(
            null,
            $file->url(),
            null
        );

        $this->assertIsString($provider->getK8sHeader());
    }

    public function testGetK8sHeaderNoTokenFile(): void
    {
        $provider = new DataProvider(
            null,
            vfsStream::url(self::TOKEN_PATH),
            null
        );

        $this->assertNull($provider->getK8sHeader());
    }

    public function testIsK8s(): void
    {
        $file = vfsStream::newFile(self::CERT_PATH)
            ->withContent('foo')
            ->at($this->root);

        $provider = new DataProvider(
            null,
            null,
            $file->url()
        );

        $this->assertTrue($provider->isK8s());
    }

    public function testIsK8sNoCertFile(): void
    {
        $provider = new DataProvider(
            null,
            null,
            vfsStream::url(self::CERT_PATH)
        );

        $this->assertFalse($provider->isK8s());
    }

    public function testGetCgroupData(): void
    {
        $file = vfsStream::newFile(self::CGROUP_PATH)
            ->withContent("foo\nbar")
            ->at($this->root)
        ;

        $provider = new DataProvider(
            $file->url(),
            null,
            null
        );

        $this->assertIsArray($provider->getCgroupData());
    }

    public function testGetCgroupDataNoCgroupFile(): void
    {
        $provider = new DataProvider(
            vfsStream::url(self::CGROUP_PATH),
            null,
            null
        );

        $this->assertNull($provider->getCgroupData());
    }
}
