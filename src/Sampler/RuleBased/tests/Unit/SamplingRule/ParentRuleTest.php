<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Tests\Unit\Sampler\RuleBased\SamplingRule;

use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule\ParentRule;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParentRule::class)]
class ParentRuleTest extends TestCase
{
    private const TRACE_ID = 'ff000000000000000000000000000041';
    private const SPAN_ID = 'ff00000000000041';
    #[DataProvider('matchesProvider')]
    public function test_matches(bool $isSampled, bool $isRemote, bool $sampled, bool $remote, $expected): void
    {
        $flags = $isSampled ? TraceFlags::SAMPLED : TraceFlags::DEFAULT;
        if ($isRemote) {
            $spanContext = SpanContext::createFromRemoteParent(self::TRACE_ID, self::SPAN_ID, $flags);
        } else {
            $spanContext = SpanContext::create(self::TRACE_ID, self::SPAN_ID, $flags);
        }

        $span = $this->createMock(SpanInterface::class);
        $span->method('getContext')->willReturn($spanContext);
        $context = $this->createMock(ContextInterface::class);
        $context->method('get')->willReturn($span);

        $rule = new ParentRule($sampled, $remote);
        $this->assertSame(
            $expected,
            $rule->matches(
                $context,
                'trace-id',
                'span-name',
                SpanKind::KIND_SERVER,
                $this->createMock(AttributesInterface::class),
                []
            )
        );
    }

    public static function matchesProvider(): array
    {
        //isSampled, isRemote, sampled, remote, expected
        return [
            'is sampled, allow sampled' => [true, false, true, false, true],
            'is sampled, allow remote' => [true, false, false, true, false],
            'is remote, allow sampled' => [false, true, true, false, false],
            'is remote, allow remote' => [false, true, false, true, true],
            'is sampled+remote, allow only sampled' => [true, true, true, false, false],
            'is sampled+remote, allow only remote' => [true, true, false, true, false],
            'is sampled+remote, allow sampled+remote' => [true, true, true, true, true],
        ];
    }

    public function test_to_string(): void
    {
        $rule = new ParentRule(true, false);
        $this->assertSame('Parent{sampled=true,remote=false}', (string) $rule);
    }
}
