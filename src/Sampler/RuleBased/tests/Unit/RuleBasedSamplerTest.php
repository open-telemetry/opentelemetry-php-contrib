<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Tests\Unit\Sampler\RuleBased;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Contrib\Sampler\RuleBased\RuleBasedSampler;
use OpenTelemetry\Contrib\Sampler\RuleBased\RuleSet;
use OpenTelemetry\Contrib\Sampler\RuleBased\RuleSetInterface;
use OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Trace\LinkInterface;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SamplingResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuleBasedSampler::class)]
class RuleBasedSamplerTest extends TestCase
{
    /** @var SamplerInterface&MockObject */
    private SamplerInterface $fallback;
    /** @var SamplerInterface&MockObject */
    private SamplerInterface $delegate;
    private RuleSetInterface $ruleSet;
    /** @var SamplingRule&MockObject */
    private SamplingRule $rule;
    private RuleBasedSampler $sampler;
    private ContextInterface $context;
    private string $traceId;
    private string $spanName;
    private int $spanKind;
    private AttributesInterface $attributes;
    /** @var list<LinkInterface> */
    private array $links;

    public function setUp(): void
    {
        $this->context = $this->createMock(ContextInterface::class);
        $this->traceId = 'some.trace.id';
        $this->spanName = 'my-span';
        $this->spanKind = SpanKind::KIND_SERVER;
        $this->attributes = $this->createMock(AttributesInterface::class);
        $this->links = [];

        $this->delegate = $this->createMock(SamplerInterface::class);
        $this->rule = $this->createMock(SamplingRule::class);
        $this->ruleSet = new RuleSet([$this->rule], $this->delegate);

        $this->fallback = $this->createMock(SamplerInterface::class);
        $this->sampler = new RuleBasedSampler([$this->ruleSet], $this->fallback);
    }

    public function test_delegates_on_ruleset_match(): void
    {
        $this->fallback
            ->expects($this->never())
            ->method('shouldSample');
        $this->delegate
            ->expects($this->once())
            ->method('shouldSample')
            ->willReturn(new SamplingResult(SamplingResult::RECORD_AND_SAMPLE));
        $this->rule
            ->expects($this->once())
            ->method('matches')
            ->willReturn(true);

        $this->sampler->shouldSample($this->context, $this->traceId, $this->spanName, $this->spanKind, $this->attributes, $this->links);
    }

    public function test_uses_fallback_when_no_match(): void
    {
        $this->fallback
            ->expects($this->once())
            ->method('shouldSample')
            ->willReturn(new SamplingResult(SamplingResult::RECORD_AND_SAMPLE));
        $this->delegate
            ->expects($this->never())
            ->method('shouldSample');
        $this->rule
            ->expects($this->once())
            ->method('matches')
            ->willReturn(false);

        $this->sampler->shouldSample($this->context, $this->traceId, $this->spanName, $this->spanKind, $this->attributes, $this->links);
    }

    public function test_get_description(): void
    {
        $this->rule->method('__toString')->willReturn('rule-one');
        $this->fallback->method('getDescription')->willReturn('fallback');
        $this->delegate->method('getDescription')->willReturn('delegate');

        $this->assertSame('RuleBasedSampler{rules=[RuleSet{rules=[rule-one],delegate=delegate}],fallback=fallback}', $this->sampler->getDescription());
    }
}
