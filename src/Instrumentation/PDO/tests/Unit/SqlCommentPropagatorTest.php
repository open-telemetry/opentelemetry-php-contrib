<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\PDO\tests\Unit;

use OpenTelemetry\Contrib\Instrumentation\PDO\SqlCommentPropagator;
use PHPUnit\Framework\TestCase;

class SqlCommentPropagatorTest extends TestCase
{
    public function testIsPrependReturnsTrue()
    {
        $_SERVER['OTEL_PHP_INSTRUMENTATION_PDO_SQL_COMMENTER_PREPEND'] = true;
        $result = SqlCommentPropagator::isPrepend();
        $this->assertTrue($result);
    }

    public function testIsPrependReturnsFalse()
    {
        $_SERVER['OTEL_PHP_INSTRUMENTATION_PDO_SQL_COMMENTER_PREPEND'] = false;
        $result = SqlCommentPropagator::isPrepend();
        $this->assertFalse($result);
    }

    public function testInjectPrepend()
    {
        $_SERVER['OTEL_PHP_INSTRUMENTATION_PDO_SQL_COMMENTER_PREPEND'] = true;
        $comments = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ];
        $query = 'SELECT 1;';
        $result = SqlCommentPropagator::inject($query, $comments);
        $this->assertEquals("/*key1='value1',key2='value2',key3='value3'*/SELECT 1;", $result);
    }

    public function testInjectAppend()
    {
        $_SERVER['OTEL_PHP_INSTRUMENTATION_PDO_SQL_COMMENTER_PREPEND'] = false;
        $comments = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ];
        $query = 'SELECT 1;';
        $result = SqlCommentPropagator::inject($query, $comments);
        $this->assertEquals("SELECT 1/*key1='value1',key2='value2',key3='value3'*/;", $result);
    }
}
