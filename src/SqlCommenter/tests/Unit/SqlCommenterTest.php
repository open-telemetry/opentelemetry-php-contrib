<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\SqlCommenter\tests\Unit;

use OpenTelemetry\Contrib\SqlCommenter\SqlCommenter;
use PHPUnit\Framework\TestCase;

class SqlCommenterTest extends TestCase
{
    public function testIsPrependReturnsTrue()
    {
        $_SERVER['OTEL_PHP_SQLCOMMENTER_PREPEND'] = true;
        $result = SqlCommenter::isPrepend();
        $this->assertTrue($result);
    }

    public function testIsPrependReturnsFalse()
    {
        $_SERVER['OTEL_PHP_SQLCOMMENTER_PREPEND'] = false;
        $result = SqlCommenter::isPrepend();
        $this->assertFalse($result);
    }

    public function testInjectPrepend()
    {
        $_SERVER['OTEL_PHP_SQLCOMMENTER_PREPEND'] = true;
        $comments = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ];
        $query = 'SELECT 1;';
        $result = SqlCommenter::inject($query, $comments);
        $this->assertEquals("/*key1='value1',key2='value2',key3='value3'*/SELECT 1;", $result);
    }

    public function testInjectAppend()
    {
        $_SERVER['OTEL_PHP_SQLCOMMENTER_PREPEND'] = false;
        $comments = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ];
        $query = 'SELECT 1;';
        $result = SqlCommenter::inject($query, $comments);
        $this->assertEquals("SELECT 1/*key1='value1',key2='value2',key3='value3'*/;", $result);
    }
}
