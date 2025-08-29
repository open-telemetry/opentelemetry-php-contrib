<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\PDO\tests\Unit;

use OpenTelemetry\Contrib\Instrumentation\PDO\ContextInfoPropagator;
use PHPUnit\Framework\TestCase;

class ContextInfoPropagatorTest extends TestCase
{
    public function testInject()
    {
        $comments = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ];
        $query = 'SELECT 1;';
        $result = ContextInfoPropagator::inject($query, $comments);
        $this->assertEquals('SELECT 1;', $result);
    }

}
