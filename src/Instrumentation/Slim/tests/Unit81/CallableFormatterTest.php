<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Slim\Unit81;

use OpenTelemetry\Contrib\Instrumentation\Slim\CallableFormatter;
use PHPUnit\Framework\TestCase;
use stdClass;

class CallableFormatterTest extends TestCase
{
    /**
     * php <8.1 can't parse this test
     */
    public function test_format_first_class_callable(): void
    {
        if (PHP_VERSION < 8.1) {
            $this->markTestSkipped();
        }
        $function = 'var_dump';
        $this->assertSame(CallableFormatter::format($function), CallableFormatter::format($function(...)));
    }
}
