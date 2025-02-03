<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Unit\Watches\RedisCommand;

use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\RedisCommand\Serializer;
use PHPUnit\Framework\TestCase;

class SerializerTest extends TestCase
{
    /**
     * @dataProvider serializeCases
     */
    public function testSerialize($command, $params, $expected): void
    {
        $this->assertSame($expected, Serializer::serializeCommand($command, $params));
    }

    public function serializeCases(): iterable
    {
        // Only serialize command
        yield ['ECHO', ['param1'], 'ECHO [1 other arguments]'];

        // Only serialize 1 params
        yield ['SET', ['param1', 'param2'], 'SET param1 [1 other arguments]'];
        yield ['SET', ['param1', 'param2', 'param3'], 'SET param1 [2 other arguments]'];

        // Only serialize 2 params
        yield ['HSET', ['param1', 'param2', 'param3'], 'HSET param1 param2 [1 other arguments]'];

        // Serialize all params
        yield ['DEL', ['param1', 'param2', 'param3', 'param4'], 'DEL param1 param2 param3 param4'];

        // Parameters of array type
        yield ['EVAL', ['param1', 'param2', ['arg1', 'arg2']], 'EVAL param1 param2 ["arg1","arg2"]'];
    }
}
