<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Tests\Unit\Sampler\RuleBased\SamplingRule;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule\SpanKindRule;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SpanKindRule::class)]
class SpanKindRuleTest extends TestCase
{
    #[DataProvider('matchesProvider')]
    public function test_matches(int $kind, int $spanKind, bool $expected): void
    {
        $rule = new SpanKindRule($kind);
        $this->assertSame($expected, $rule->matches(Context::getRoot(), 'trace-id', 'foo', $spanKind, Attributes::create([]), []));
    }

    public static function matchesProvider(): array
    {
        return [
            [SpanKind::KIND_INTERNAL, SpanKind::KIND_INTERNAL, true],
            [SpanKind::KIND_INTERNAL, SpanKind::KIND_SERVER, false],
        ];
    }

    public function test_to_string(): void
    {
        $rule = new SpanKindRule(SpanKind::KIND_SERVER);

        $this->assertSame('SpanKind{kind=2}', (string) $rule);
    }
}
