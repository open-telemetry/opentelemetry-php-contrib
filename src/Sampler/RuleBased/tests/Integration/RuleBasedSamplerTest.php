<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Tests\Integration\Sampler\RuleBased;

use OpenTelemetry\Config\SDK\Configuration;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
class RuleBasedSamplerTest extends TestCase
{
    #[DataProvider('configFileProvider')]
    public function test_open_telemetry_configuration(string $file): void
    {
        $this->expectNotToPerformAssertions();
        Configuration::parseFile($file)->create();
    }

    public static function configFileProvider(): iterable
    {
        yield [__DIR__ . '/config/sdk-config.yaml'];
    }
}
