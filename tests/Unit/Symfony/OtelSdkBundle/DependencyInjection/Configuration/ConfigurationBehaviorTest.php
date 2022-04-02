<?php

declare(strict_types=1);

namespace OpenTelemetry\Test\Unit\Symfony\OtelSdkBundle\DependencyInjection\Configuration;

use Generator;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\ConfigurationBehavior;
use PHPUnit\Framework\MockObject\Rule\InvokedCount as InvokedCountMatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration\ConfigurationBehavior
 */
class ConfigurationBehaviorTest extends TestCase
{
    public const CONFIG_NAME = 'foo';
    public const CONFIG_DISABLED = true;

    public function test_logger(): void
    {
        $instance = $this->createInstance();
        $logger = $this->createLoggerMock();

        $instance->setLogger($logger);

        $this->assertSame(
            $logger,
            $instance->getLogger()
        );
    }

    /**
     * @dataProvider debugProvider
     */
    public function test_debug(bool $debug, InvokedCountMatcher $count): void
    {
        $instance = $this->createInstance();
        $logger = $this->createLoggerMock();
        $logger->expects($count)
            ->method('debug');

        $instance->setLogger($logger);
        $instance->setDebug($debug);

        $instance->doDebug('foo', []);
    }

    /**
     * @dataProvider debugProvider
     */
    public function test_start_debug(bool $debug, InvokedCountMatcher $count): void
    {
        $instance = $this->createInstance();
        $logger = $this->createLoggerMock();
        $logger->expects($count)
            ->method('debug');

        $instance->setLogger($logger);
        $instance->setDebug($debug);

        $instance->doStartDebug();
    }

    /**
     * @dataProvider debugProvider
     */
    public function test_end_debug(bool $debug, InvokedCountMatcher $count): void
    {
        $instance = $this->createInstance();
        $logger = $this->createLoggerMock();
        $logger->expects($count)
            ->method('debug');

        $instance->setLogger($logger);
        $instance->setDebug($debug);

        $instance->doEndDebug();
    }

    public function debugProvider(): Generator
    {
        yield [true, $this->once()];
        yield [false, $this->never()];
    }

    public function test_get_name(): void
    {
        $this->assertSame(
            self::CONFIG_NAME,
            $this->createInstance()->getName()
        );
    }

    public function test_can_be_disabled(): void
    {
        $this->assertSame(
            self::CONFIG_DISABLED,
            $this->createInstance()->canBeDisabled()
        );
    }

    private function createInstance(): object
    {
        return new class() {
            use ConfigurationBehavior;

            public function doDebug(string $message, array $context = []): void
            {
                $this->debug($message, $context);
            }

            public function doStartDebug(): void
            {
                $this->startDebug();
            }

            public function doEndDebug(): void
            {
                $this->endDebug();
            }

            public static function getName(): string
            {
                return ConfigurationBehaviorTest::CONFIG_NAME;
            }

            public static function canBeDisabled(): bool
            {
                return ConfigurationBehaviorTest::CONFIG_DISABLED;
            }
        };
    }

    private function createLoggerMock()
    {
        return $this->createMock(LoggerInterface::class);
    }
}
