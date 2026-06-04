<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Unit;

use OpenTelemetry\Contrib\Instrumentation\Laravel\InstrumentationConfig;
use PHPUnit\Framework\TestCase;

class InstrumentationConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        InstrumentationConfig::reset();
        putenv('OTEL_LARAVEL_ENABLED_INSTRUMENTATIONS');
        putenv('OTEL_LARAVEL_DISABLED_INSTRUMENTATIONS');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('OTEL_LARAVEL_ENABLED_INSTRUMENTATIONS');
        putenv('OTEL_LARAVEL_DISABLED_INSTRUMENTATIONS');
        InstrumentationConfig::reset();
    }

    public function testAllEnabledByDefault(): void
    {
        $config = InstrumentationConfig::getInstance();

        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::HTTP));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::CONSOLE));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::QUEUE));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::ELOQUENT));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::SERVE));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::CACHE));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::DB));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::HTTP_CLIENT));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::EXCEPTION));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::LOG));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::REDIS));
    }

    public function testEnabledOnlySpecified(): void
    {
        putenv('OTEL_LARAVEL_ENABLED_INSTRUMENTATIONS=http,queue');

        $config = InstrumentationConfig::getInstance();

        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::HTTP));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::QUEUE));
        $this->assertFalse($config->isInstrumentationEnabled(InstrumentationConfig::ELOQUENT));
        $this->assertFalse($config->isInstrumentationEnabled(InstrumentationConfig::CACHE));
        $this->assertFalse($config->isInstrumentationEnabled(InstrumentationConfig::CONSOLE));
    }

    public function testDisabledRemovesFromEnabled(): void
    {
        putenv('OTEL_LARAVEL_DISABLED_INSTRUMENTATIONS=cache,redis');

        $config = InstrumentationConfig::getInstance();

        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::HTTP));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::QUEUE));
        $this->assertFalse($config->isInstrumentationEnabled(InstrumentationConfig::CACHE));
        $this->assertFalse($config->isInstrumentationEnabled(InstrumentationConfig::REDIS));
    }

    public function testDisabledPriorityOverEnabled(): void
    {
        putenv('OTEL_LARAVEL_ENABLED_INSTRUMENTATIONS=all');
        putenv('OTEL_LARAVEL_DISABLED_INSTRUMENTATIONS=redis,log');

        $config = InstrumentationConfig::getInstance();

        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::HTTP));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::QUEUE));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::CACHE));
        $this->assertFalse($config->isInstrumentationEnabled(InstrumentationConfig::REDIS));
        $this->assertFalse($config->isInstrumentationEnabled(InstrumentationConfig::LOG));
    }

    public function testWatchersAliasExpands(): void
    {
        putenv('OTEL_LARAVEL_ENABLED_INSTRUMENTATIONS=http,watchers');

        $config = InstrumentationConfig::getInstance();

        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::HTTP));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::CACHE));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::DB));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::HTTP_CLIENT));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::EXCEPTION));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::LOG));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::REDIS));
        $this->assertFalse($config->isInstrumentationEnabled(InstrumentationConfig::QUEUE));
        $this->assertFalse($config->isInstrumentationEnabled(InstrumentationConfig::CONSOLE));
    }

    public function testAllAliasExpands(): void
    {
        putenv('OTEL_LARAVEL_ENABLED_INSTRUMENTATIONS=all');

        $config = InstrumentationConfig::getInstance();

        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::HTTP));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::CONSOLE));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::QUEUE));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::ELOQUENT));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::SERVE));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::CACHE));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::DB));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::HTTP_CLIENT));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::EXCEPTION));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::LOG));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::REDIS));
    }

    public function testHasAnyWatcherEnabledWhenWatcherEnabled(): void
    {
        putenv('OTEL_LARAVEL_ENABLED_INSTRUMENTATIONS=http,cache');

        $config = InstrumentationConfig::getInstance();

        $this->assertTrue($config->hasAnyWatcherEnabled());
    }

    public function testHasAnyWatcherEnabledWhenNoWatcherEnabled(): void
    {
        putenv('OTEL_LARAVEL_ENABLED_INSTRUMENTATIONS=http,queue');

        $config = InstrumentationConfig::getInstance();

        $this->assertFalse($config->hasAnyWatcherEnabled());
    }

    public function testTrimsWhitespace(): void
    {
        putenv('OTEL_LARAVEL_ENABLED_INSTRUMENTATIONS= http , queue ');

        $config = InstrumentationConfig::getInstance();

        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::HTTP));
        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::QUEUE));
    }

    public function testUnknownInstrumentationIsIncludedIfSpecified(): void
    {
        putenv('OTEL_LARAVEL_ENABLED_INSTRUMENTATIONS=http,unknown-instrumentation');

        $config = InstrumentationConfig::getInstance();

        $this->assertTrue($config->isInstrumentationEnabled(InstrumentationConfig::HTTP));
        // Unknown instrumentations are still added to the enabled list if specified
        $this->assertTrue($config->isInstrumentationEnabled('unknown-instrumentation'));
        // But other known instrumentations are not enabled
        $this->assertFalse($config->isInstrumentationEnabled(InstrumentationConfig::QUEUE));
    }
}
