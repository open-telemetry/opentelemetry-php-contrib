<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\Test\Unit\OtelBundle;

use function dirname;
use function file_get_contents;
use function json_decode;
use OpenTelemetry\Symfony\OtelBundle\OtelBundle;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OpenTelemetry\Symfony\OtelBundle\OtelBundle
 */
final class OtelBundleTest extends TestCase
{
    public function testInstrumentationNameMatchesComposerJsonName(): void
    {
        $reflection = new ReflectionClass(OtelBundle::class);
        if (!$content = file_get_contents(dirname($reflection->getFileName()) . '/composer.json')) {
            $this->fail();
        }

        $expectedName = json_decode($content)->name ?? null;

        $this->assertSame($expectedName, OtelBundle::instrumentationName());
    }
}
