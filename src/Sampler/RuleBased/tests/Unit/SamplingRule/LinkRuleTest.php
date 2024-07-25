<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Tests\Unit\Sampler\RuleBased\SamplingRule;

use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule\LinkRule;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Trace\Link;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LinkRule::class)]
class LinkRuleTest extends TestCase
{
    #[DataProvider('matchesProvider')]
    public function test_matches(bool $isSampled, bool $isRemote, bool $sampled, bool $remote, $expected): void
    {
        $flags = $isSampled ? TraceFlags::SAMPLED : TraceFlags::DEFAULT;
        if ($isRemote) {
            $spanContext = SpanContext::createFromRemoteParent('some.remote.trace.id', 'some.remote.span.id', $flags);
        } else {
            $spanContext = SpanContext::create('some.trace.id', 'some.span.id', $flags);
        }
        $link = new Link(
            $spanContext,
            $this->createMock(AttributesInterface::class)
        );

        $rule = new LinkRule($sampled, $remote);
        $this->assertSame(
            $expected,
            $rule->matches(
                $this->createMock(ContextInterface::class),
                'trace-id',
                'span-name',
                SpanKind::KIND_SERVER,
                $this->createMock(AttributesInterface::class),
                [$link]
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
        $rule = new LinkRule(true, false);
        $this->assertSame('Link{sampled=true,remote=false}', (string) $rule);
    }
}
