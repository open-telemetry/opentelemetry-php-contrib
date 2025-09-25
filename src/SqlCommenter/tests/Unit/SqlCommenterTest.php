<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\SqlCommenter\tests\Unit;

use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Contrib\SqlCommenter\SqlCommenter;
use PHPUnit\Framework\TestCase;

final class SqlCommenterTestPropagator implements TextMapPropagatorInterface
{
    #[\Override]
    public function inject(&$carrier, ?PropagationSetterInterface $setter = null, ?ContextInterface $context = null): void
    {
        $setter ??= ArrayAccessGetterSetter::getInstance();
        $setter->set($carrier, 'key1', 'value1');
        $setter->set($carrier, 'key2', 'value2');
        $setter->set($carrier, 'key3', 'value3');
    }

    #[\Override]
    public function extract($carrier, ?PropagationGetterInterface $getter = null, ?ContextInterface $context = null): ContextInterface
    {
        return $context ?? Context::getCurrent();
    }

    #[\Override]
    public function fields(): array
    {
        return ['key1', 'key2', 'key3'];
    }
}
class SqlCommenterTest extends TestCase
{
    private SqlCommenter $commenter;
    #[\Override]
    protected function setUp(): void
    {
        $this->commenter = SqlCommenter::getInstance();
    }
    public function testIsPrependReturnsTrue()
    {
        $_SERVER['OTEL_PHP_SQLCOMMENTER_PREPEND'] = true;
        $result = $this->commenter->isPrepend();
        $this->assertTrue($result);
    }

    public function testIsPrependReturnsFalse()
    {
        $_SERVER['OTEL_PHP_SQLCOMMENTER_PREPEND'] = false;
        $result = $this->commenter->isPrepend();
        $this->assertFalse($result);
    }

    public function testInjectPrepend()
    {
        $commenter = new SqlCommenter(new SqlCommenterTestPropagator());
        $_SERVER['OTEL_PHP_SQLCOMMENTER_PREPEND'] = true;
        $query = 'SELECT 1;';
        $result = $commenter->inject($query);
        $this->assertEquals("/*key1='value1',key2='value2',key3='value3'*/SELECT 1;", $result);
    }

    public function testInjectAppend()
    {
        $commenter = new SqlCommenter(new SqlCommenterTestPropagator());
        $_SERVER['OTEL_PHP_SQLCOMMENTER_PREPEND'] = false;
        $query = 'SELECT 1;';
        $result = $commenter->inject($query);
        $this->assertEquals("SELECT 1/*key1='value1',key2='value2',key3='value3'*/;", $result);
    }

    public function testIsAttributeEnabledReturnsTrue()
    {
        $_SERVER['OTEL_PHP_SQLCOMMENTER_ATTRIBUTE'] = true;
        $result = $this->commenter->isAttributeEnabled();
        $this->assertTrue($result);
    }

    public function testIsAttributeEnabledReturnsFalse()
    {
        $_SERVER['OTEL_PHP_SQLCOMMENTER_ATTRIBUTE'] = false;
        $result = $this->commenter->isAttributeEnabled();
        $this->assertFalse($result);
    }
}
