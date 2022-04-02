<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\Util;

use InvalidArgumentException;
use OpenTelemetry\Symfony\OtelSdkBundle\Util\ExporterDsnParser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\Util\ExporterDsnParser
 */
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
        'password' => 'secret',
    ];

    public function testParseFullDsn(): void
    {
        $dsn = 'foo+bar://root:secret@baz:1234/path?key=value';

        $this->assertEquals(
            ExporterDsnParser::parse($dsn),
            ExporterDsnParser::parse($dsn)
        );
    }

    public function testParseToArrayFullDsn(): void
    {
        $dsn = 'foo+bar://root:secret@baz:1234/path?key=value';

        $this->assertEquals(
            self::PARTS_ARRAY,
            ExporterDsnParser::parseToArray($dsn)
        );
    }

    public function testParseToArrayNoAuth(): void
    {
        $dsn = 'foo+bar://baz:1234/path?key=value';
        $parts = self::PARTS_ARRAY;
        unset($parts['user'], $parts['password']);

        $this->assertEquals(
            $parts,
            ExporterDsnParser::parseToArray($dsn)
        );
    }

    public function testParseToArrayNoPort(): void
    {
        $dsn = 'foo+bar://root:secret@baz/path?key=value';
        $parts = self::PARTS_ARRAY;
        unset($parts['port']);

        $this->assertEquals(
            $parts,
            ExporterDsnParser::parseToArray($dsn)
        );
    }

    public function testParseToArrayNoPath(): void
    {
        $dsn = 'foo+bar://root:secret@baz:1234?key=value';
        $parts = self::PARTS_ARRAY;
        unset($parts['path']);

        $this->assertEquals(
            $parts,
            ExporterDsnParser::parseToArray($dsn)
        );
    }

    public function testParseToArrayNoOptions(): void
    {
        $dsn = 'foo+bar://root:secret@baz:1234/path';
        $parts = self::PARTS_ARRAY;
        $parts['options'] = [];

        $this->assertEquals(
            $parts,
            ExporterDsnParser::parseToArray($dsn)
        );
    }

    public function testParseToArrayNoSchemeThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExporterDsnParser::parseToArray('root:secret@baz:1234/path');
    }

    public function testParseInvalidDsnThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExporterDsnParser::parse('http://user@:80"');
    }

    public function testParseMissingTypeThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExporterDsnParser::parse('bar://root:secret@baz:1234/path');
    }
}
