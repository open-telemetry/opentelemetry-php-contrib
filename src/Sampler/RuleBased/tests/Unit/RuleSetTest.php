<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Tests\Unit\Sampler\RuleBased;

use OpenTelemetry\Contrib\Sampler\RuleBased\RuleSet;
use OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuleSet::class)]
class RuleSetTest extends TestCase
{
    /** @var SamplerInterface&MockObject */
    private SamplerInterface $delegate;
    /** @var SamplingRule&MockObject */
    private SamplingRule $rule_one;
    /** @var SamplingRule&MockObject */
    private SamplingRule $rule_two;

    public function setUp(): void
    {
        $this->delegate = $this->createMock(SamplerInterface::class);
        $this->rule_one = $this->createMock(SamplingRule::class);
        $this->rule_two = $this->createMock(SamplingRule::class);
    }

    public function test_getters(): void
    {
        $rules = [$this->rule_one, $this->rule_two];
        $ruleSet = new RuleSet($rules, $this->delegate);

        $this->assertSame($rules, $ruleSet->samplingRules());
        $this->assertSame($this->delegate, $ruleSet->delegate());
    }

    public function test_to_string(): void
    {
        $this->rule_one->expects($this->once())->method('__toString')->willReturn('rule_one');
        $this->rule_two->expects($this->once())->method('__toString')->willReturn('rule_two');
        $this->delegate->expects($this->once())->method('getDescription')->willReturn('delegate');
        $ruleSet = new RuleSet([$this->rule_one, $this->rule_two], $this->delegate);

        $this->assertSame('RuleSet{rules=[rule_one,rule_two],delegate=delegate}', (string) $ruleSet);
    }
}
