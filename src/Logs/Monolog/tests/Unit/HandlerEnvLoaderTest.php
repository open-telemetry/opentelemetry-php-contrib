<?php

declare(strict_types=1);

use OpenTelemetry\API\Configuration\ConfigEnv\EnvComponentLoaderRegistry;
use OpenTelemetry\API\Configuration\ConfigEnv\EnvResolver;
use OpenTelemetry\API\Configuration\Context;
use OpenTelemetry\Contrib\Logs\Monolog\ConfigEnv\HandlerEnvLoader;
use OpenTelemetry\Contrib\Logs\Monolog\Handler;
use OpenTelemetry\Contrib\Logs\Monolog\HandlerConfiguration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\Contrib\Logs\Monolog\ConfigEnv\HandlerEnvLoader
 */
class HandlerEnvLoaderTest extends TestCase
{
    /** @var EnvResolver&\PHPUnit\Framework\MockObject\MockObject */
    private EnvResolver $env;
    /** @var EnvComponentLoaderRegistry&\PHPUnit\Framework\MockObject\MockObject */
    private EnvComponentLoaderRegistry $registry;
    private Context $context;

    public function setUp(): void
    {
        $this->env = $this->createMock(EnvResolver::class);
        $this->registry = $this->createMock(EnvComponentLoaderRegistry::class);
        $this->context = new Context();
    }

    public function test_name_returns_configuration_class(): void
    {
        $this->assertSame(HandlerConfiguration::class, (new HandlerEnvLoader())->name());
    }

    public function test_load_returns_default_mode_when_env_not_set(): void
    {
        $this->env->method('enum')->willReturn(null);

        $config = (new HandlerEnvLoader())->load($this->env, $this->registry, $this->context);

        $this->assertSame(Handler::DEFAULT_MODE, $config->mode);
    }

    public function test_load_returns_mode_from_env(): void
    {
        $this->env->method('enum')
            ->with(Handler::OTEL_PHP_MONOLOG_ATTRIB_MODE, Handler::MODES)
            ->willReturn(Handler::MODE_OTEL);

        $config = (new HandlerEnvLoader())->load($this->env, $this->registry, $this->context);

        $this->assertSame(Handler::MODE_OTEL, $config->mode);
    }
}
