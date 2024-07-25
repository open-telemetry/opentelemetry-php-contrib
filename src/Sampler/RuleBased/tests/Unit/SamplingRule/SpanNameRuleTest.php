<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Tests\Unit\Sampler\RuleBased\SamplingRule;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule\SpanNameRule;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SpanNameRule::class)]
class SpanNameRuleTest extends TestCase
{
    /**
     * @param non-empty-string $pattern
     */
    #[DataProvider('matchesProvider')]
    public function test_matches(string $pattern, string $spanName, bool $expected): void
    {
        $rule = new SpanNameRule($pattern);
        $this->assertSame($expected, $rule->matches(Context::getRoot(), 'trace-id', $spanName, SpanKind::KIND_INTERNAL, Attributes::create([]), []));
    }

    public static function matchesProvider(): array
    {
        return [
            ['~foo~', 'foo', true],
            ['~bar~', 'foobarbaz', true],
            ['~foo~', 'bar', false],
            ['~^bar$~', 'Xbar', false],
        ];
    }

    public function test_to_string(): void
    {
        $rule = new SpanNameRule('~foo~');

        $this->assertSame('SpanName{pattern=~foo~}', (string) $rule);
    }
}
