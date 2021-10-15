<?php

declare(strict_types=1);

namespace instrumentation\symfony\Util;

use OpenTelemetry\Instrumentation\Symfony\OpenTelemetryBundle\Util\ExporterDsnParser;
use OpenTelemetry\Instrumentation\Symfony\OpenTelemetryBundle\Util\ExporterDsn;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

class ExporterDsnParserTest extends TestCase
{
    private const PARTS_ARRAY = [
        'type' => 'foo',
        'scheme' => 'bar',
        'host' => 'baz',
        'path' => '/path',
        'port' => 1234,
        'options' => ['key'=>'value'],
        'user' => 'root',
        'password' => 'secret'
    ];

    public function testParseFullDsn()
    {
        $dsn = 'foo+bar://root:secret@baz:1234/path?key=value';

        $this->assertInstanceOf(
            ExporterDsn::class,
            ExporterDsnParser::parse($dsn)
        );
    }

    public function testParseToArrayFullDsn()
    {
        $dsn = 'foo+bar://root:secret@baz:1234/path?key=value';

        $this->assertEquals(
            self::PARTS_ARRAY,
            ExporterDsnParser::parseToArray($dsn)
        );
    }

    public function testParseToArrayNoAuth()
    {
        $dsn = 'foo+bar://baz:1234/path?key=value';
        $parts = self::PARTS_ARRAY;
        unset($parts['user'] );
        unset($parts['password'] );

        $this->assertEquals(
            $parts,
            ExporterDsnParser::parseToArray($dsn)
        );
    }

    public function testParseToArrayNoPort()
    {
        $dsn = 'foo+bar://root:secret@baz/path?key=value';
        $parts = self::PARTS_ARRAY;
        unset($parts['port'] );

        $this->assertEquals(
            $parts,
            ExporterDsnParser::parseToArray($dsn)
        );
    }

    public function testParseToArrayNoPath()
    {
        $dsn = 'foo+bar://root:secret@baz:1234?key=value';
        $parts = self::PARTS_ARRAY;
        unset($parts['path'] );

        $this->assertEquals(
            $parts,
            ExporterDsnParser::parseToArray($dsn)
        );
    }

    public function testParseToArrayNoOptions()
    {
        $dsn = 'foo+bar://root:secret@baz:1234/path';
        $parts = self::PARTS_ARRAY;
        $parts['options'] = [];

        $this->assertEquals(
            $parts,
            ExporterDsnParser::parseToArray($dsn)
        );
    }

    public function testParseInvalidDsnThrowsException()
    {
        $this->expectException(InvalidArgumentException::class);

        ExporterDsnParser::parse('http://user@:80"');
    }

    public function testParseMissingTypeThrowsException()
    {
        $this->expectException(InvalidArgumentException::class);

        ExporterDsnParser::parse('bar://root:secret@baz:1234/path');
    }
}