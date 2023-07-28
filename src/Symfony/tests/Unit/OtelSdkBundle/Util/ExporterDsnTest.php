<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Symfony\Unit\OtelSdkBundle\Util;

use InvalidArgumentException;
use OpenTelemetry\Contrib\Symfony\OtelSdkBundle\Util\ExporterDsn;
use PHPUnit\Framework\TestCase;

class ExporterDsnTest extends TestCase
{
    private const ARG_ARRAY = [
        'type' => 'foo',
        'scheme' => 'bar',
        'host' => 'baz',
        'path' => '/path',
        'port' => 1234,
        'options' => ['key'=>'value'],
        'user' => 'root',
        'password' => 'secret',
    ];

    public function testInstantiationWithRequiredArguments()
    {
        $this->assertInstanceOf(
            ExporterDsn::class,
            new ExporterDsn('foo', 'bar', 'baz')
        );
    }

    public function testInstantiationWithAllArguments()
    {
        $this->assertInstanceOf(
            ExporterDsn::class,
            new ExporterDsn('foo', 'bar', 'baz', '/path', 321, ['foo'=>'bar'], 'user', 'pw')
        );
    }

    public function testFromArray()
    {
        $this->assertInstanceOf(
            ExporterDsn::class,
            ExporterDsn::fromArray(self::ARG_ARRAY)
        );
    }

    public function testFromArrayThrowsExceptionWithoutType()
    {
        $this->expectException(InvalidArgumentException::class);

        ExporterDsn::fromArray(['scheme' => 'a','host' => 'b']);
    }

    public function testFromArrayThrowsExceptionWithoutScheme()
    {
        $this->expectException(InvalidArgumentException::class);

        ExporterDsn::fromArray(['type' => 'a','host' => 'b']);
    }

    public function testFromArrayThrowsExceptionWithoutHost()
    {
        $this->expectException(InvalidArgumentException::class);

        ExporterDsn::fromArray(['type' => 'a','scheme' => 'b']);
    }

    public function testGetType()
    {
        $this->assertSame(
            self::ARG_ARRAY['type'],
            ExporterDsn::fromArray(self::ARG_ARRAY)->getType()
        );
    }

    public function testGetScheme()
    {
        $this->assertSame(
            self::ARG_ARRAY['scheme'],
            ExporterDsn::fromArray(self::ARG_ARRAY)->getScheme()
        );
    }

    public function testGetHost()
    {
        $this->assertSame(
            self::ARG_ARRAY['host'],
            ExporterDsn::fromArray(self::ARG_ARRAY)->getHost()
        );
    }

    public function testGetPath()
    {
        $this->assertSame(
            self::ARG_ARRAY['path'],
            ExporterDsn::fromArray(self::ARG_ARRAY)->getPath()
        );
    }

    public function testGetPort()
    {
        $this->assertSame(
            self::ARG_ARRAY['port'],
            ExporterDsn::fromArray(self::ARG_ARRAY)->getPort()
        );
    }

    public function testGetOptions()
    {
        $this->assertSame(
            self::ARG_ARRAY['options'],
            ExporterDsn::fromArray(self::ARG_ARRAY)->getOptions()
        );
    }

    public function testGetUser()
    {
        $this->assertSame(
            self::ARG_ARRAY['user'],
            ExporterDsn::fromArray(self::ARG_ARRAY)->getUser()
        );
    }

    public function testGetPassword()
    {
        $this->assertSame(
            self::ARG_ARRAY['password'],
            ExporterDsn::fromArray(self::ARG_ARRAY)->getPassword()
        );
    }

    public function testToString()
    {
        $this->assertSame(
            'foo+bar://root:secret@baz:1234/path?key=value',
            (string) ExporterDsn::fromArray(self::ARG_ARRAY)
        );
    }

    public function testToStringNoOptions()
    {
        $args = self::ARG_ARRAY;
        unset($args['options']);
        $this->assertSame(
            'foo+bar://root:secret@baz:1234/path',
            (string) ExporterDsn::fromArray($args)
        );
    }

    public function testGetEndpoint()
    {
        $this->assertSame(
            'bar://root:secret@baz:1234/path',
            ExporterDsn::fromArray(self::ARG_ARRAY)->getEndpoint()
        );
    }

    public function testGetEndpointNoAuth()
    {
        $expected = 'bar://baz:1234/path';

        $args = self::ARG_ARRAY;
        unset($args['user']);
        $this->assertSame(
            $expected,
            ExporterDsn::fromArray($args)->getEndpoint()
        );

        $args = self::ARG_ARRAY;
        unset($args['password']);
        $this->assertSame(
            $expected,
            ExporterDsn::fromArray($args)->getEndpoint()
        );
    }

    public function testGetEndpointNoPort()
    {
        $args = self::ARG_ARRAY;
        unset($args['port']);
        $this->assertSame(
            'bar://root:secret@baz/path',
            ExporterDsn::fromArray($args)->getEndpoint()
        );
    }

    public function testGetEndpointNoPath()
    {
        $args = self::ARG_ARRAY;
        unset($args['path']);
        $this->assertSame(
            'bar://root:secret@baz:1234',
            ExporterDsn::fromArray($args)->getEndpoint()
        );
    }

    public function testAsConfigArray()
    {
        $args = self::ARG_ARRAY;
        unset($args['path']);
        $this->assertSame(
            [
                'type' => 'foo',
                'url' => 'bar://root:secret@baz:1234/path',
                'options' => ['key'=>'value'],
            ],
            ExporterDsn::fromArray(self::ARG_ARRAY)->asConfigArray()
        );
    }
}
