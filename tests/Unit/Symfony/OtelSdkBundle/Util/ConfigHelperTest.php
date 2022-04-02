<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\Util;

use OpenTelemetry\Symfony\OtelSdkBundle\Util\ConfigHelper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\Util\ConfigHelper
 */
class ConfigHelperTest extends TestCase
{
    public function testCreateReference(): void
    {
        $this->assertEquals(
            ConfigHelper::createReference('foo'),
            ConfigHelper::createReference('foo')
        );
    }

    public function testWrapParameter(): void
    {
        $this->assertSame(
            '%foo%',
            ConfigHelper::wrapParameter('foo')
        );
    }

    public function testCreateReferenceFromClass(): void
    {
        $this->assertEquals(
            ConfigHelper::createReferenceFromClass(__CLASS__),
            ConfigHelper::createReferenceFromClass(__CLASS__)
        );
    }
}
