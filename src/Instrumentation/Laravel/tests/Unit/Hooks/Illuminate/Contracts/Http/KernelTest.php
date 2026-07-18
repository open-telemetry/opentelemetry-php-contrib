<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Unit\Hooks\Illuminate\Contracts\Http;

use Illuminate\Http\Request;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Contracts\Http\Kernel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

class KernelTest extends TestCase
{
    public function test_http_method_swallows_suspicious_operation_exception(): void
    {
        $request = new class() extends Request {
            public function method(): string
            {
                throw new SuspiciousOperationException('Invalid method override.');
            }
        };

        $this->assertSame('unknown', $this->invokeGuard('httpMethod', $request));
    }

    public function test_http_full_url_swallows_suspicious_operation_exception(): void
    {
        $request = new class() extends Request {
            public function fullUrl(): string
            {
                throw new SuspiciousOperationException('Invalid Host.');
            }
        };

        $this->assertSame('', $this->invokeGuard('httpFullUrl', $request));
    }

    public function test_http_host_name_swallows_suspicious_operation_exception(): void
    {
        $request = new class() extends Request {
            public function host(): string
            {
                throw new SuspiciousOperationException('Invalid Host.');
            }
        };

        $this->assertSame('', $this->invokeGuard('httpHostName', $request));
    }

    private function invokeGuard(string $method, Request $request): string
    {
        $kernel = (new ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();

        $reflectionMethod = new ReflectionMethod(Kernel::class, $method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invoke($kernel, $request);
    }
}
