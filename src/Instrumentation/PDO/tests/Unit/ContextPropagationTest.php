<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\PDO\tests\Unit;

use OpenTelemetry\Contrib\Instrumentation\PDO\ContextPropagation;
use PHPUnit\Framework\TestCase;

class ContextPropagationTest extends TestCase
{
    public function testIsEnabledReturnsTrue()
    {
        $_SERVER['OTEL_PHP_INSTRUMENTATION_PDO_CONTEXT_PROPAGATION'] = true;
        $result = ContextPropagation::isEnabled();
        $this->assertTrue($result);
    }

    public function testIsEnabledReturnsFalse()
    {
        $_SERVER['OTEL_PHP_INSTRUMENTATION_PDO_CONTEXT_PROPAGATION'] = false;
        $result = ContextPropagation::isEnabled();
        $this->assertFalse($result);
    }

    public function testIsPrependReturnsTrue()
    {
        $_SERVER['OTEL_PHP_INSTRUMENTATION_PDO_CONTEXT_PROPAGATION_PREPEND'] = true;
        $result = ContextPropagation::isPrepend();
        $this->assertTrue($result);
    }

    public function testIsPrependReturnsFalse()
    {
        $_SERVER['OTEL_PHP_INSTRUMENTATION_PDO_CONTEXT_PROPAGATION_PREPEND'] = false;
        $result = ContextPropagation::isPrepend();
        $this->assertFalse($result);
    }

    public function testIsAttributeEnabledReturnsTrue()
    {
        $_SERVER['OTEL_PHP_INSTRUMENTATION_PDO_CONTEXT_PROPAGATION_ATTRIBUTE'] = true;
        $result = ContextPropagation::isAttributeEnabled();
        $this->assertTrue($result);
    }

    public function testIsAttributeEnabledReturnsFalse()
    {
        $_SERVER['OTEL_PHP_INSTRUMENTATION_PDO_CONTEXT_PROPAGATION_ATTRIBUTE'] = false;
        $result = ContextPropagation::isAttributeEnabled();
        $this->assertFalse($result);
    }

    public function testAddSqlCommentsPrepend()
    {
        $_SERVER['OTEL_PHP_INSTRUMENTATION_PDO_CONTEXT_PROPAGATION_PREPEND'] = true;
        $comments = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ];
        $query = 'SELECT 1;';
        $result = ContextPropagation::addSqlComments($query, $comments);
        $this->assertEquals("/*key1='value1',key2='value2',key3='value3'*/SELECT 1;", $result);
    }

    public function testAddSqlCommentsAppend()
    {
        $_SERVER['OTEL_PHP_INSTRUMENTATION_PDO_CONTEXT_PROPAGATION_PREPEND'] = false;
        $comments = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ];
        $query = 'SELECT 1;';
        $result = ContextPropagation::addSqlComments($query, $comments);
        $this->assertEquals("SELECT 1/*key1='value1',key2='value2',key3='value3'*/;", $result);
    }

    public function testIsOptInDatabase()
    {
        $this->assertTrue(ContextPropagation::isOptInDatabase('postgresql'));
        $this->assertTrue(ContextPropagation::isOptInDatabase('mysql'));
        $this->assertFalse(ContextPropagation::isOptInDatabase('sqlite'));
    }
}
