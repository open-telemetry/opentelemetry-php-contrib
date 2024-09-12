<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Tests\Unit\Sampler\RuleBased\SamplingRule;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule\AttributeRule;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AttributeRule::class)]
class AttributeRuleTest extends TestCase
{
    #[DataProvider('attributesProvider')]
    public function test_matches(AttributesInterface $attributes, bool $expected): void
    {
        $rule = new AttributeRule('foo', '~foo~');

        $this->assertSame(
            $expected,
            $rule->matches(
                $this->createMock(ContextInterface::class),
                'trace-id',
                'span-name',
                SpanKind::KIND_SERVER,
                $attributes,
                []
            )
        );
    }

    public static function attributesProvider(): iterable
    {
        yield [Attributes::create(['foo' => 'foo']), true];
        yield [Attributes::create(['foo' => 'bar']), false];
        yield [Attributes::create(['bar' => 'foo']), false];
    }

    public function test_to_string(): void
    {
        $rule = new AttributeRule('foo', '~foo~');

        $this->assertSame('Attribute{key=foo,pattern=~foo~}', (string) $rule);
    }
}
