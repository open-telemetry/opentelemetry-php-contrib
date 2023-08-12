<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Symfony\Unit\OtelSdkBundle\Util;

use OpenTelemetry\Contrib\Symfony\OtelSdkBundle\Util\ConfigHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Reference;

class ConfigHelperTest extends TestCase
{
    public function testCreateReference()
    {
        $this->assertInstanceOf(
            Reference::class,
            ConfigHelper::createReference('foo')
        );
    }

    public function testWrapParameter()
    {
        $this->assertSame(
            '%foo%',
            ConfigHelper::wrapParameter('foo')
        );
    }

    public function testCreateReferenceFromClass()
    {
        $this->assertInstanceOf(
            Reference::class,
            ConfigHelper::createReferenceFromClass(__CLASS__)
        );
    }
}
