<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Resource\Detector\Container\Container;
use OpenTelemetry\SemConv\ResourceAttributes;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamFile;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenTelemetry\Contrib\Resource\Detector\Container\Container
 */
class ContainerTest extends TestCase
{
    private vfsStreamFile $cgroup;
    private vfsStreamFile $mountinfo;
    private Container $detector;

    public function setUp(): void
    {
        $root = vfsStream::setup();
        $this->cgroup = vfsStream::newFile('cgroup')->at($root);
        $this->mountinfo = vfsStream::newFile('mountinfo')->at($root);
        $this->detector = new Container($root->url());
    }

    /**
     * cgroup (v1) should take precedence over mountinfo (v2)
     * @dataProvider cgroupMountinfoProvider
     */
    public function test_with_cgroup_and_mountinfo(string $cgroup, string $mountinfo, string $expected): void
    {
        $cgroup && $this->cgroup->setContent($cgroup);
        $mountinfo && $this->mountinfo->setContent($mountinfo);
        $resource = $this->detector->getResource();

        $this->assertSame($expected, $resource->getAttributes()->get(ResourceAttributes::CONTAINER_ID));
    }

    public static function cgroupMountinfoProvider(): array
    {
        return [
            'k8s' => [
                file_get_contents(__DIR__ . '/fixtures/v1.cgroup.k8s.txt'),
                file_get_contents(__DIR__ . '/fixtures/v2.mountinfo.k8s.txt'),
                '78ea929aa43e7b71f7c36583d82038d92a76800bf5da9b8850e8bd7b514bc075',
            ],
            'docker with invalid cgroup' => [
                'no-container-ids-here',
                file_get_contents(__DIR__ . '/fixtures/v2.mountinfo.docker.txt'),
                'a8493b8a4f6f23b65c5db50be86619ca4da078da040aa3d5ccff26fe50de205d',
            ],
            'podman' => [
                'no-container-ids-here',
                file_get_contents(__DIR__ . '/fixtures/v2.mountinfo.podman.txt'),
                '2a33efc76e519c137fe6093179653788bed6162d4a15e5131c8e835c968afbe6',
            ],
        ];
    }

    /**
     * @dataProvider cgroupProvider
     */
    public function test_valid_v1(string $data, string $expected): void
    {
        $this->cgroup->setContent($data);
        $resource = $this->detector->getResource();

        $this->assertSame(ResourceAttributes::SCHEMA_URL, $resource->getSchemaUrl());
        $this->assertIsString($resource->getAttributes()->get(ResourceAttributes::CONTAINER_ID));
        $this->assertSame($expected, $resource->getAttributes()->get(ResourceAttributes::CONTAINER_ID));
    }

    public static function cgroupProvider(): array
    {
        return [
            'docker' => [
                file_get_contents(__DIR__ . '/fixtures/v1.cgroup.docker.txt'),
                '7be92808767a667f35c8505cbf40d14e931ef6db5b0210329cf193b15ba9d605',
            ],
            'k8s' => [
                file_get_contents(__DIR__ . '/fixtures/v1.cgroup.k8s.txt'),
                '78ea929aa43e7b71f7c36583d82038d92a76800bf5da9b8850e8bd7b514bc075',
            ],
        ];
    }

    public function test_invalid_v1(): void
    {
        $this->cgroup->setContent('0::/');
        $resource = $this->detector->getResource();

        $this->assertEmpty($resource->getAttributes());
    }

    /**
     * @dataProvider mountinfoProvider
     */
    public function test_valid_v2(string $data, string $expected): void
    {
        $this->mountinfo->withContent($data);
        $resource = $this->detector->getResource();

        $this->assertCount(1, $resource->getAttributes());
        $this->assertSame($expected, $resource->getAttributes()->get(ResourceAttributes::CONTAINER_ID));
    }

    public static function mountinfoProvider(): array
    {
        return [
            'docker' => [
                file_get_contents(__DIR__ . '/fixtures/v2.mountinfo.docker.txt'),
                'a8493b8a4f6f23b65c5db50be86619ca4da078da040aa3d5ccff26fe50de205d',
            ],
            'podman' => [
                file_get_contents(__DIR__ . '/fixtures/v2.mountinfo.podman.txt'),
                '2a33efc76e519c137fe6093179653788bed6162d4a15e5131c8e835c968afbe6',
            ],
        ];
    }

    public function test_invalid_v2(): void
    {
        $data = <<< EOS
1581 1359 259:2 /var/lib/docker/containers/a8493b8a4f6f23b65c5db50be86619ca4da078da040aa3d5ccff26fe50de205d/wrongkeyword
EOS;
        $this->mountinfo->withContent($data);
        $resource = $this->detector->getResource();

        $this->assertEmpty($resource->getAttributes());
    }
}
