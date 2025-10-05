<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\SqlCommenter\tests\Unit;

use OpenTelemetry\Contrib\SqlCommenter\Utils;
use PHPUnit\Framework\TestCase;

class UtilsTest extends TestCase
{
    public function testFormatCommentsWithKeys(): void
    {
        $this->assertEquals("/*key1='value1',key2='value2'*/", Utils::formatComments(['key1' => 'value1', 'key2' => 'value2']));
    }

    public function testFormatCommentsWithoutKeys(): void
    {
        $this->assertEquals('', Utils::formatComments([]));
    }

    public function testFormatCommentsWithSpecialCharKeys(): void
    {
        $this->assertEquals("/*key1='value1%%40',key2='value2'*/", Utils::formatComments(['key1' => 'value1@', 'key2' => 'value2']));
    }

    public function testFormatCommentsWithPlaceholder(): void
    {
        $this->assertEquals("/*key1='value1%%3F',key2='value2'*/", Utils::formatComments(['key1' => 'value1?', 'key2' => 'value2']));
    }

    public function testFormatCommentsWithNamedPlaceholder(): void
    {
        $this->assertEquals("/*key1='%%3Anamed',key2='value2'*/", Utils::formatComments(['key1' => ':named', 'key2' => 'value2']));
    }
}
