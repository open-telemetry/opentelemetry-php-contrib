<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Tests\Integration\Sampler\RuleBased;

use Nevay\SPI\ServiceLoader;
use OpenTelemetry\API\Configuration\Config\ComponentProvider;
use OpenTelemetry\Contrib\Sampler\RuleBased\ComponentProvider\SamplerRuleBased;
use OpenTelemetry\Contrib\Sampler\RuleBased\ComponentProvider\SamplingRuleAttribute;
use OpenTelemetry\Contrib\Sampler\RuleBased\ComponentProvider\SamplingRuleLink;
use OpenTelemetry\Contrib\Sampler\RuleBased\ComponentProvider\SamplingRuleParent;
use OpenTelemetry\Contrib\Sampler\RuleBased\ComponentProvider\SamplingRuleSpanKind;
use OpenTelemetry\Contrib\Sampler\RuleBased\ComponentProvider\SamplingRuleSpanName;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
class ComponentProviderRegistrationTest extends TestCase
{
    /**
     * @param class-string<ComponentProvider> $provider
     */
    #[DataProvider('providerClasses')]
    public function test_provider_is_registered_under_the_api_key(string $provider): void
    {
        $registered = [];
        foreach (ServiceLoader::load(ComponentProvider::class) as $class => $_) {
            $registered[] = $class;
        }

        $this->assertContains($provider, $registered);
    }

    public static function providerClasses(): iterable
    {
        yield [SamplerRuleBased::class];
        yield [SamplingRuleAttribute::class];
        yield [SamplingRuleLink::class];
        yield [SamplingRuleParent::class];
        yield [SamplingRuleSpanKind::class];
        yield [SamplingRuleSpanName::class];
    }
}
